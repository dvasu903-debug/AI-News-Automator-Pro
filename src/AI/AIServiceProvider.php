<?php

declare(strict_types=1);

namespace AINewsAutomator\AI;

use AINewsAutomator\AI\Admin\AISettingsPage;
use AINewsAutomator\AI\Cache\TransientResponseCache;
use AINewsAutomator\AI\Config\ProviderConfig;
use AINewsAutomator\AI\Contracts\AIRequestValidatorInterface;
use AINewsAutomator\AI\Contracts\CostCalculatorInterface;
use AINewsAutomator\AI\Contracts\FailoverPolicyInterface;
use AINewsAutomator\AI\Contracts\ModelCatalogInterface;
use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\AI\Contracts\PromptTemplateRepositoryInterface;
use AINewsAutomator\AI\Contracts\ResponseCacheInterface;
use AINewsAutomator\AI\Contracts\RetryPolicyInterface;
use AINewsAutomator\AI\Cost\ModelCatalogCostCalculator;
use AINewsAutomator\AI\Health\AIProviderHealthCheck;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\AI\Manager\ExponentialBackoffRetryPolicy;
use AINewsAutomator\AI\Manager\FailoverChain;
use AINewsAutomator\AI\Manager\RetryExecutor;
use AINewsAutomator\AI\ModelCatalog\StaticModelCatalog;
use AINewsAutomator\AI\Prompt\PromptTemplateRepository;
use AINewsAutomator\AI\Providers\ClaudeProvider;
use AINewsAutomator\AI\Providers\GeminiProvider;
use AINewsAutomator\AI\Providers\OpenAiCompatibleProvider;
use AINewsAutomator\AI\Registry\ProviderRegistry;
use AINewsAutomator\AI\Storage\AiMigrationManifest;
use AINewsAutomator\AI\Validation\AIRequestValidator;
use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Settings\SettingsRegistry;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Security\Contracts\NonceManagerInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Security\Secrets\CredentialVault;
use AINewsAutomator\Storage\Contracts\AiRequestRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Migrations\MigrationRunner;

/**
 * The AI module's single service provider. Wires: the provider registry
 * (populated with 7 provider instances — 2 dedicated adapters + 5
 * OpenAI-compatible configs), the orchestration layer (AIManager,
 * RetryExecutor, FailoverChain), model catalog + cost calculator, prompt
 * templates (via Storage's reused, not modified, repository/migration
 * classes), response cache, and the AI settings page.
 *
 * No provider is EVER instantiated outside this file — everything else
 * resolves providers through ProviderRegistryInterface.
 */
final class AIServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->registerModelCatalogAndCost($container);
        $this->registerCacheAndRetry($container);
        $this->registerValidationAndRegistry($container);
        $this->registerProviders($container);
        $this->registerPromptTemplates($container);
        $this->registerManager($container);
        $this->registerHealthAndAdmin($container);
    }

    private function registerModelCatalogAndCost(ContainerInterface $container): void
    {
        $container->singleton(
            ModelCatalogInterface::class,
            static fn (ContainerInterface $c): ModelCatalogInterface => new StaticModelCatalog($c->get(LoggerInterface::class))
        );

        $container->singleton(
            CostCalculatorInterface::class,
            static fn (ContainerInterface $c): CostCalculatorInterface => new ModelCatalogCostCalculator($c->get(ModelCatalogInterface::class))
        );
    }

    private function registerCacheAndRetry(ContainerInterface $container): void
    {
        $container->singleton(ResponseCacheInterface::class, static fn (): ResponseCacheInterface => new TransientResponseCache());

        $container->singleton(RetryPolicyInterface::class, static fn (): RetryPolicyInterface => new ExponentialBackoffRetryPolicy());

        $container->singleton(
            RetryExecutor::class,
            static fn (ContainerInterface $c): RetryExecutor => new RetryExecutor(
                $c->get(RetryPolicyInterface::class),
                $c->get(LoggerInterface::class)
            )
        );
    }

    private function registerValidationAndRegistry(ContainerInterface $container): void
    {
        $container->singleton(
            ProviderRegistryInterface::class,
            static fn (ContainerInterface $c): ProviderRegistryInterface => new ProviderRegistry($c->get(ConfigRepositoryInterface::class))
        );

        $container->singleton(
            AIRequestValidatorInterface::class,
            static fn (ContainerInterface $c): AIRequestValidatorInterface => new AIRequestValidator($c->get(ModelCatalogInterface::class))
        );

        $container->singleton(
            FailoverPolicyInterface::class,
            static fn (ContainerInterface $c): FailoverPolicyInterface => new FailoverChain(
                $c->get(ProviderRegistryInterface::class),
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class)
            )
        );
    }

    /**
     * Registers all 7 example providers named in the requirements: 2
     * dedicated adapters (Claude, Gemini) + 5 vendors sharing
     * OpenAiCompatibleProvider via ProviderConfig alone (OpenAI,
     * OpenRouter, DeepSeek, Grok, Ollama). Adding another OpenAI-
     * compatible vendor means adding one more ProviderConfig here — no
     * new class, per the approved architecture decision.
     */
    private function registerProviders(ContainerInterface $container): void
    {
        $container->singleton(
            ClaudeProvider::class,
            static fn (ContainerInterface $c): ClaudeProvider => new ClaudeProvider(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class),
                $c->get(SecretsProviderInterface::class)
            )
        );

        $container->singleton(
            GeminiProvider::class,
            static fn (ContainerInterface $c): GeminiProvider => new GeminiProvider(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class),
                $c->get(SecretsProviderInterface::class)
            )
        );

        foreach ($this->openAiCompatibleConfigs() as $configId => $configFactory) {
            $container->singleton(
                'ai.provider.' . $configId,
                function (ContainerInterface $c) use ($configFactory): OpenAiCompatibleProvider {
                    return new OpenAiCompatibleProvider(
                        $configFactory($c->get(ConfigRepositoryInterface::class)),
                        $c->get(OutboundHttpValidator::class),
                        $c->get(LoggerInterface::class),
                        $c->get(SecretsProviderInterface::class)
                    );
                }
            );
        }
    }

    /**
     * @return array<string, callable(ConfigRepositoryInterface): ProviderConfig>
     */
    private function openAiCompatibleConfigs(): array
    {
        return [
            'openai' => static fn (): ProviderConfig => new ProviderConfig(
                id: 'openai',
                displayName: 'OpenAI',
                baseUrl: 'https://api.openai.com/v1',
                secretKey: 'ai.openai.api_key',
            ),
            'openrouter' => static fn (): ProviderConfig => new ProviderConfig(
                id: 'openrouter',
                displayName: 'OpenRouter',
                baseUrl: 'https://openrouter.ai/api/v1',
                secretKey: 'ai.openrouter.api_key',
                // OpenRouter's own capability is "whatever model is routed" —
                // see design doc Part 4. Flags here are the coarse default;
                // ModelCatalogInterface is the authoritative per-model check.
            ),
            'deepseek' => static fn (): ProviderConfig => new ProviderConfig(
                id: 'deepseek',
                displayName: 'DeepSeek',
                baseUrl: 'https://api.deepseek.com',
                secretKey: 'ai.deepseek.api_key',
                supportsVision: false, // DeepSeek's native API is text-only — verified capability matrix, Part 4.
            ),
            'grok' => static fn (): ProviderConfig => new ProviderConfig(
                id: 'grok',
                displayName: 'Grok (xAI)',
                baseUrl: 'https://api.x.ai/v1',
                secretKey: 'ai.grok.api_key',
            ),
            'ollama' => static fn (ConfigRepositoryInterface $config): ProviderConfig => new ProviderConfig(
                id: 'ollama',
                displayName: 'Ollama (local)',
                baseUrl: (string) $config->get('ai.ollama.base_url', 'http://localhost:11434/v1'),
                secretKey: null, // Local, typically no auth. See module README: Security's URL
                // allowlist must be configured by the admin for the local/private
                // address Ollama typically runs on — UrlGuard's SSRF defaults are
                // NOT weakened to accommodate this.
                supportsStructuredOutput: true,
            ),
        ];
    }

    private function registerPromptTemplates(ContainerInterface $container): void
    {
        $container->singleton(
            PromptTemplateRepositoryInterface::class,
            static fn (ContainerInterface $c): PromptTemplateRepositoryInterface => new PromptTemplateRepository($c->get(ConnectionInterface::class))
        );
    }

    private function registerManager(ContainerInterface $container): void
    {
        $container->singleton(
            AIManager::class,
            static fn (ContainerInterface $c): AIManager => new AIManager(
                $c->get(ProviderRegistryInterface::class),
                $c->get(AIRequestValidatorInterface::class),
                $c->get(ResponseCacheInterface::class),
                $c->get(RateLimiterInterface::class),
                $c->get(RetryExecutor::class),
                $c->get(FailoverPolicyInterface::class),
                $c->get(CostCalculatorInterface::class),
                $c->get(AiRequestRepositoryInterface::class),
                $c->get(MetricsRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class),
                $c->get(CorrelationContext::class)
            )
        );
    }

    private function registerHealthAndAdmin(ContainerInterface $container): void
    {
        $container->singleton(
            AIProviderHealthCheck::class,
            static fn (ContainerInterface $c): AIProviderHealthCheck => new AIProviderHealthCheck($c->get(ProviderRegistryInterface::class))
        );

        $container->singleton(
            AISettingsPage::class,
            static fn (ContainerInterface $c): AISettingsPage => new AISettingsPage(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(CredentialVault::class),
                $c->get(CapabilityGateInterface::class),
                $c->get(NonceManagerInterface::class),
                $c->get(ProviderRegistryInterface::class),
                $c->get(AIProviderHealthCheck::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
        $this->populateProviderRegistry($container);

        /** @var SettingsRegistry $settings */
        $settings = $container->get(SettingsRegistry::class);
        $settings->register(AISettingsPage::class);

        add_action('admin_post_ana_ai_save_key', static function () use ($container): void {
            $container->get(AISettingsPage::class)->handleSaveApiKey();
        });

        // Automatic upgrade detection for the AI module's own tables —
        // same pattern as Storage's own boot(), reusing Storage's
        // MigrationRunner singleton with the AI module's own manifest.
        add_action('plugins_loaded', static function () use ($container): void {
            /** @var MigrationRunner $runner */
            $runner = $container->get(MigrationRunner::class);
            $migrations = AiMigrationManifest::migrations();

            if ($runner->hasPending($migrations)) {
                $runner->migrate($migrations);
            }
        }, 6); // After Storage's own check (priority 5), before Core's default-priority work.
    }

    private function populateProviderRegistry(ContainerInterface $container): void
    {
        /** @var ProviderRegistryInterface $registry */
        $registry = $container->get(ProviderRegistryInterface::class);

        $registry->register($container->get(ClaudeProvider::class));
        $registry->register($container->get(GeminiProvider::class));

        foreach (array_keys($this->openAiCompatibleConfigs()) as $configId) {
            $registry->register($container->get('ai.provider.' . $configId));
        }
    }

    public function activate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var MigrationRunner $runner */
        $runner = $this->container->get(MigrationRunner::class);
        $runner->migrate(AiMigrationManifest::migrations());
    }

    public function deactivate(): void
    {
        // Reversible — no data destroyed on plain deactivation.
    }

    public function uninstall(): void
    {
        global $wpdb;

        foreach (['prompt_history', 'prompt_templates'] as $logical) {
            $table = $wpdb->prefix . 'ana_' . $logical;
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}

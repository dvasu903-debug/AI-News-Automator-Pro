<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI\Fakes;

use AINewsAutomator\AI\Cache\TransientResponseCache;
use AINewsAutomator\AI\Cost\ModelCatalogCostCalculator;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\AI\Manager\ExponentialBackoffRetryPolicy;
use AINewsAutomator\AI\Manager\FailoverChain;
use AINewsAutomator\AI\Manager\RetryExecutor;
use AINewsAutomator\AI\ModelCatalog\StaticModelCatalog;
use AINewsAutomator\AI\Registry\ProviderRegistry;
use AINewsAutomator\AI\Validation\AIRequestValidator;
use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Security\RateLimit\TransientRateLimiter;

/**
 * Builds a fully-wired AIManager from fakes, for orchestration tests.
 * Returns every collaborator the test might want to assert against, so
 * each test file doesn't hand-assemble the same 12-argument constructor.
 */
final class AIManagerTestFactory
{
    /**
     * @param list<FakeChatProvider> $providers
     * @param array<string, mixed> $configOverrides
     */
    public static function build(array $providers, array $configOverrides = []): AIManagerTestHarness
    {
        $config = new OptionBackedConfigRepository(array_merge([
            'ai' => [
                'defaults' => ['chat' => $providers[0]->id()],
                'failover' => [
                    'priority'           => array_map(static fn (FakeChatProvider $p): string => $p->id(), $providers),
                    'excluded_providers' => [],
                ],
            ],
        ], $configOverrides));

        $registry = new ProviderRegistry($config);
        foreach ($providers as $provider) {
            $registry->register($provider);
        }

        $logger = new OptionBackedLogger(new CorrelationContext('test-correlation'), Environment::Development);
        $events = new EventDispatcher();
        $correlation = new CorrelationContext('test-correlation');
        $metadataFactory = new EventMetadataFactory($correlation);

        $catalog = new StaticModelCatalog($logger);
        $costCalculator = new ModelCatalogCostCalculator($catalog);
        $cache = new TransientResponseCache();
        $rateLimiter = new TransientRateLimiter();
        $retryExecutor = new RetryExecutor(new ExponentialBackoffRetryPolicy(maxAttempts: 3, baseDelayMs: 1, maxDelayMs: 2), $logger);
        $failover = new FailoverChain($registry, $config, $logger);
        $validator = new AIRequestValidator($catalog);
        $requestRepository = new FakeAiRequestRepository();
        $metrics = new FakeMetricsRepository();

        $manager = new AIManager(
            $registry,
            $validator,
            $cache,
            $rateLimiter,
            $retryExecutor,
            $failover,
            $costCalculator,
            $requestRepository,
            $metrics,
            $events,
            $metadataFactory,
            $correlation,
        );

        return new AIManagerTestHarness($manager, $registry, $events, $requestRepository, $metrics, $cache);
    }
}

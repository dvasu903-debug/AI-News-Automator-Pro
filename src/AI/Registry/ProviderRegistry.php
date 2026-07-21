<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Registry;

use AINewsAutomator\AI\Contracts\AIProviderInterface;
use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;

/**
 * The single source of truth for "which provider instances exist and
 * which one is the default for a given capability." AIManager, and every
 * future module, resolves providers exclusively through this class —
 * never `new ClaudeProvider(...)` anywhere outside AIServiceProvider's
 * registration wiring.
 */
final class ProviderRegistry implements ProviderRegistryInterface
{
    /** @var array<string, AIProviderInterface> */
    private array $providers = [];

    public function __construct(private readonly ConfigRepositoryInterface $config)
    {
    }

    public function register(AIProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    public function get(string $providerId): ?AIProviderInterface
    {
        return $this->providers[$providerId] ?? null;
    }

    public function all(): array
    {
        return array_values($this->providers);
    }

    public function allImplementing(string $interfaceFqcn): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (AIProviderInterface $p): bool => $p instanceof $interfaceFqcn
        ));
    }

    public function defaultFor(string $capability): ?AIProviderInterface
    {
        $providerId = $this->config->get('ai.defaults.' . $capability);

        if (!is_string($providerId) || $providerId === '') {
            return null;
        }

        return $this->get($providerId);
    }
}

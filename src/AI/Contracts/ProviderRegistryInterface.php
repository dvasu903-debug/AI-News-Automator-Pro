<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

/**
 * The ONLY way AIManager (or anything else) discovers or resolves a
 * provider instance. Nothing in the AI module — or any future module —
 * instantiates a provider class directly; every resolution goes through
 * this registry, which is what makes "add a provider via configuration"
 * true in practice, not just in principle.
 */
interface ProviderRegistryInterface
{
    public function register(AIProviderInterface $provider): void;

    public function get(string $providerId): ?AIProviderInterface;

    /**
     * @return list<AIProviderInterface>
     */
    public function all(): array;

    /**
     * @param class-string $interfaceFqcn e.g. ChatProviderInterface::class
     * @return list<AIProviderInterface>
     */
    public function allImplementing(string $interfaceFqcn): array;

    /**
     * The configured default provider for a capability (e.g. "chat" ->
     * "claude"), read from Core's ConfigRepositoryInterface. Returns null
     * if none is configured or the configured provider isn't registered.
     */
    public function defaultFor(string $capability): ?AIProviderInterface;
}

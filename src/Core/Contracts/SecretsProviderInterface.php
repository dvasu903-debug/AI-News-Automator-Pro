<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Contract for reading/writing encrypted secrets (API keys, tokens).
 *
 * Core defines the contract and PluginConfig exposes access to whatever
 * implementation is bound, but Core intentionally ships only a null-ish
 * default. The real encryption-at-rest implementation (libsodium-backed,
 * key derived from WordPress salts) belongs to Module 2 (Security), which
 * will bind its concrete class to this interface. Defining the contract
 * here — rather than in Security — lets PluginConfig and any Core-era code
 * reference secrets through a stable seam now, so nothing needs rewiring
 * when Security lands.
 */
interface SecretsProviderInterface
{
    /**
     * Returns the decrypted secret for the given key, or null if unset.
     */
    public function get(string $key): ?string;

    /**
     * Encrypts and stores a secret.
     */
    public function set(string $key, string $value): void;

    public function has(string $key): bool;

    public function forget(string $key): void;
}

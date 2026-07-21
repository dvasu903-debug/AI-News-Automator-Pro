<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

/**
 * Ephemeral, transient-backed response caching — deliberately not a
 * database table (see approved architecture decision, consistent with
 * Security's RateLimiter precedent). A cache miss must never throw; it
 * simply returns null.
 */
interface ResponseCacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}

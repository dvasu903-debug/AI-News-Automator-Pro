<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Cache;

use AINewsAutomator\AI\Contracts\ResponseCacheInterface;

/**
 * Ephemeral, transient-backed response cache — deliberately not a
 * database table (approved architecture decision, consistent with
 * Security's TransientRateLimiter precedent). A cache miss or backend
 * failure returns null, never throws — caching must never be the reason
 * an AI request fails.
 */
final class TransientResponseCache implements ResponseCacheInterface
{
    private const PREFIX = 'ana_ai_cache_';

    public function get(string $key): mixed
    {
        $value = get_transient(self::PREFIX . md5($key));

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        set_transient(self::PREFIX . md5($key), $value, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        delete_transient(self::PREFIX . md5($key));
    }
}

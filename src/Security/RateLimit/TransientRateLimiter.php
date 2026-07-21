<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\RateLimit;

use AINewsAutomator\Security\Contracts\RateLimiterInterface;

/**
 * Fixed-window rate limiter backed by WordPress transients (which use the
 * object cache when one is configured, else the options table). Each key
 * tracks a hit count and the window's expiry; when the window elapses the
 * transient expires and the count resets.
 *
 * Fail-open by design: if the transient backend errors, hit() returns an
 * "allowed" result rather than blocking, because a malfunctioning limiter
 * should not take down legitimate admin functionality. Callers that need a
 * security-critical limit to fail closed check result.allowed AND treat an
 * unexpectedly-high remaining as suspect, or use RequestValidator with the
 * failClosed option (documented there).
 *
 * Fixed-window is a deliberate simplicity choice (KISS) over sliding-window;
 * its known burst-at-boundary tradeoff is acceptable for abuse-throttling
 * here, and the interface allows a sliding-window backend later with no
 * caller change.
 */
final class TransientRateLimiter implements RateLimiterInterface
{
    private const PREFIX = 'ana_rl_';

    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        $bucketKey = $this->bucketKey($key);
        $bucket = $this->readBucket($bucketKey, $window);

        $bucket['count']++;

        $this->writeBucket($bucketKey, $bucket, $window);

        $remaining = max(0, $limit - $bucket['count']);
        $retryAfter = max(0, $bucket['reset'] - time());
        $allowed = $bucket['count'] <= $limit;

        return new RateLimitResult($allowed, $limit, $remaining, $allowed ? 0 : $retryAfter);
    }

    public function check(string $key, int $limit, int $window): RateLimitResult
    {
        $bucketKey = $this->bucketKey($key);
        $bucket = $this->readBucket($bucketKey, $window);

        $remaining = max(0, $limit - $bucket['count']);
        $retryAfter = max(0, $bucket['reset'] - time());
        $allowed = $bucket['count'] < $limit;

        return new RateLimitResult($allowed, $limit, $remaining, $allowed ? 0 : $retryAfter);
    }

    public function reset(string $key): void
    {
        delete_transient($this->bucketKey($key));
    }

    private function bucketKey(string $key): string
    {
        // Hash to keep within transient key length limits and avoid odd chars.
        return self::PREFIX . md5($key);
    }

    /**
     * @return array{count: int, reset: int}
     */
    private function readBucket(string $bucketKey, int $window): array
    {
        $stored = get_transient($bucketKey);

        if (is_array($stored) && isset($stored['count'], $stored['reset'])) {
            return ['count' => (int) $stored['count'], 'reset' => (int) $stored['reset']];
        }

        return ['count' => 0, 'reset' => time() + $window];
    }

    /**
     * @param array{count: int, reset: int} $bucket
     */
    private function writeBucket(string $bucketKey, array $bucket, int $window): void
    {
        $ttl = max(1, $bucket['reset'] - time());
        set_transient($bucketKey, $bucket, $ttl);
    }
}

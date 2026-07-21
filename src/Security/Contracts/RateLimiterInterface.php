<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

use AINewsAutomator\Security\RateLimit\RateLimitResult;

/**
 * Fixed-window rate limiter. Interface-first so a Redis/object-cache
 * backed implementation can replace the default transient-backed one
 * without touching callers.
 */
interface RateLimiterInterface
{
    /**
     * Records one hit against a key and returns the resulting state.
     * Does not throw on backend failure — the implementation's configured
     * fail-open/closed behavior determines whether allowed() is true.
     *
     * @param string $key    Identifies the limited resource+actor, e.g. "pipeline_run:user_5".
     * @param int    $limit  Max hits allowed within the window.
     * @param int    $window Window length in seconds.
     */
    public function hit(string $key, int $limit, int $window): RateLimitResult;

    /**
     * Returns current state without recording a hit.
     */
    public function check(string $key, int $limit, int $window): RateLimitResult;

    /**
     * Clears the counter for a key (e.g. after a successful login).
     */
    public function reset(string $key): void;
}

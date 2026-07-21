<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\RateLimit;

/**
 * The state of a rate-limit key after a hit()/check(): whether the action
 * is allowed, how many hits remain, and seconds until the window resets.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

/**
 * Immutable snapshot of who is asking for what, passed to every policy so
 * decisions are made from a consistent view rather than each policy
 * re-reading globals. Includes the actor, their IP, and free-form context
 * (e.g. the target resource id).
 */
final class AuthorizationContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $ip,
        public readonly array $context = [],
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->userId > 0;
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }
}

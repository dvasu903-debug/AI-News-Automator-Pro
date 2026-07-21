<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * FUTURE EXTENSION POINT (not implemented in Module 2). Temporary lockouts
 * after repeated failures (brute-force mitigation).
 */
interface AccountLockoutInterface
{
    public function isLockedOut(string $identifier): bool;

    public function recordFailure(string $identifier): void;

    public function clear(string $identifier): void;
}

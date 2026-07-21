<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * FUTURE EXTENSION POINT (not implemented in Module 2). Pluggable second-
 * factor challenge/verification (TOTP, WebAuthn, etc.). Defined now so a
 * later implementation is a pure addition: bind the concrete, register it,
 * done — no change to existing Security code.
 */
interface TwoFactorProviderInterface
{
    public function isEnrolled(int $userId): bool;

    public function challenge(int $userId): void;

    public function verify(int $userId, string $code): bool;
}

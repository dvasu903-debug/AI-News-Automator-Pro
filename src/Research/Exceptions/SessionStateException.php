<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Exceptions;

/**
 * Thrown when an operation is attempted against a ResearchSession in an
 * incompatible state (e.g. adding evidence to a completed session).
 */
final class SessionStateException extends ResearchException
{
    public static function invalidTransition(int $sessionId, string $from, string $to): self
    {
        return new self(sprintf('Research session %d cannot transition from "%s" to "%s".', $sessionId, $from, $to));
    }

    public static function notGathering(int $sessionId, string $currentStatus): self
    {
        return new self(sprintf('Research session %d is not accepting evidence (status: "%s").', $sessionId, $currentStatus));
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Exceptions;

/**
 * Thrown when nonce verification fails (missing, expired, or wrong action).
 * Translated to a 403 by callers.
 */
final class NonceException extends SecurityException
{
    public static function forAction(string $action): self
    {
        return new self(sprintf('Nonce verification failed for action "%s".', $action));
    }
}

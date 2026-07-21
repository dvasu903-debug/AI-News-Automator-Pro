<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Exceptions;

/**
 * Thrown when an ability check is denied. Callers translate this to an
 * HTTP 403. Carries the denied ability for logging/response context.
 */
final class AuthorizationException extends SecurityException
{
    public static function forAbility(string $ability): self
    {
        return new self(sprintf('Authorization denied for ability "%s".', $ability));
    }
}

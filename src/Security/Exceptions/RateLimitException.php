<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Exceptions;

/**
 * Thrown when a rate limit is exceeded and the caller opted to treat that
 * as fatal. Translated to HTTP 429 by callers. Carries seconds-until-reset.
 */
final class RateLimitException extends SecurityException
{
    public function __construct(string $message, public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}

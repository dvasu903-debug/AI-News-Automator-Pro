<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Exceptions;

/**
 * Thrown when a provider is unreachable (outage) or has exhausted its
 * retry budget. Distinct from a validation/auth failure — this is what
 * triggers FailoverChain to consider the next eligible provider.
 */
final class ProviderUnavailableException extends AIException
{
    public static function afterRetries(string $providerId, int $attempts, \Throwable $last): self
    {
        return new self(
            sprintf('Provider "%s" unavailable after %d attempt(s): %s', $providerId, $attempts, $last->getMessage()),
            AIErrorType::ProviderOutage,
            $providerId,
            $last
        );
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Exceptions;

/**
 * Thrown when a request needs a capability (vision, tool calling, image
 * generation, ...) that the resolved provider+model does not support.
 * Never retryable — retrying the identical request against the identical
 * provider cannot change what that provider supports.
 */
final class UnsupportedCapabilityException extends AIException
{
    public static function for(string $providerId, string $capability, ?string $model = null): self
    {
        $target = $model !== null ? sprintf('%s (model: %s)', $providerId, $model) : $providerId;

        return new self(
            sprintf('Provider "%s" does not support capability "%s".', $target, $capability),
            AIErrorType::UnsupportedCapability,
            $providerId
        );
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Exceptions;

/**
 * Thrown when a migration fails to apply. Carries the failing version so
 * the runner can log/report exactly which migration broke.
 */
final class MigrationException extends StorageException
{
    public static function forVersion(string $version, string $reason): self
    {
        return new self(sprintf('Migration "%s" failed: %s', $version, $reason));
    }
}

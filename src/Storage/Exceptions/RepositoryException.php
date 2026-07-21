<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Exceptions;

/**
 * Thrown for repository-level failures that aren't validation errors —
 * e.g. a record-not-found on an operation that requires one to exist.
 */
final class RepositoryException extends StorageException
{
    public static function notFound(string $repository, int|string $id): self
    {
        return new self(sprintf('%s: no record found for id "%s".', $repository, (string) $id));
    }
}

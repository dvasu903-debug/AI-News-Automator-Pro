<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Exceptions;

/**
 * Thrown by a repository's validate() step before persistence. Carries the
 * field-level errors so callers (settings forms, REST controllers) can
 * surface them meaningfully rather than a generic failure.
 */
final class ValidationException extends StorageException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(private readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

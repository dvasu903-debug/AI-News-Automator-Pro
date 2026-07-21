<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Exceptions;

/**
 * Thrown when a discovered item or a source configuration fails
 * validation. Never retryable — the same malformed item will fail the
 * same way every time.
 */
final class SourceValidationException extends SourceException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Source item failed validation.')
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

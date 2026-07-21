<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Exceptions;

/**
 * Thrown by AIRequestValidatorInterface when a request's shape is invalid
 * (missing required fields, out-of-range values) — before any provider is
 * ever contacted. Never retryable as-is; the caller must fix the request.
 */
final class AIValidationException extends AIException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'AI request failed validation.')
    {
        parent::__construct($message, AIErrorType::Validation);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

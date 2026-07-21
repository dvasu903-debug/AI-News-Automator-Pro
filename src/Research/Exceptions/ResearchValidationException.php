<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Exceptions;

/**
 * Thrown when a research entity (session, evidence, claim) fails
 * validation before persistence.
 */
final class ResearchValidationException extends ResearchException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Research entity failed validation.')
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

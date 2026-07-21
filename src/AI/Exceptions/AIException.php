<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Exceptions;

/**
 * Base for every AI-module exception. Always carries an AIErrorType so
 * callers (especially RetryExecutor and FailoverChain) can make a
 * classified decision instead of pattern-matching exception messages.
 */
class AIException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly AIErrorType $errorType = AIErrorType::Unknown,
        private readonly ?string $providerId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorType(): AIErrorType
    {
        return $this->errorType;
    }

    public function providerId(): ?string
    {
        return $this->providerId;
    }

    public function isRetryable(): bool
    {
        return $this->errorType->isRetryable();
    }
}

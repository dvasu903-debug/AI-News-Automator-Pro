<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Exceptions;

use AINewsAutomator\Sources\Retry\SourceFetchErrorType;

/**
 * Thrown by a connector when a fetch fails. Always carries a
 * SourceFetchErrorType so SourceRetryExecutor can make a classified
 * decision instead of retrying every failure indiscriminately.
 */
final class SourceFetchException extends SourceException
{
    public function __construct(
        string $message,
        private readonly SourceFetchErrorType $errorType = SourceFetchErrorType::Unknown,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorType(): SourceFetchErrorType
    {
        return $this->errorType;
    }

    public function isRetryable(): bool
    {
        return $this->errorType->isRetryable();
    }
}

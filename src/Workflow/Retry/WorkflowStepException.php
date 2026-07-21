<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Retry;

use AINewsAutomator\Workflow\Entities\WorkflowStepErrorType;

/**
 * Carries a WorkflowStepErrorType so WorkflowStepRetryExecutor can
 * classify retryable vs. non-retryable step failures. An action's
 * execute() (or the Runner's own dispatch) throws this rather than a
 * bare \RuntimeException so the retry decision isn't a string-message
 * guess.
 */
final class WorkflowStepException extends \RuntimeException
{
    public function __construct(string $message, private readonly WorkflowStepErrorType $errorType, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorType(): WorkflowStepErrorType
    {
        return $this->errorType;
    }

    public function isRetryable(): bool
    {
        return $this->errorType->isRetryable();
    }

    public static function fromThrowable(\Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        // Unclassified failures default to Unknown (non-retryable) —
        // the same safe-default posture SourceFetchErrorType uses.
        return new self($e->getMessage(), WorkflowStepErrorType::Unknown, $e);
    }
}

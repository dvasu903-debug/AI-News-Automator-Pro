<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

/**
 * Classifies why a workflow step execution failed, for
 * WorkflowStepRetryExecutor — Workflow's own narrow cousin of
 * Sources\Retry\SourceFetchErrorType and AI's AIErrorType, per ADR-0016/
 * ADR-0017 (Part 6): duplicates the algorithm shape, not the
 * architecture. A step's action can throw a WorkflowStepException
 * carrying one of these, or the Runner classifies a generic \Throwable
 * as Unknown (non-retryable — safe default).
 */
enum WorkflowStepErrorType: string
{
    case Transient    = 'transient';     // retryable — e.g. a downstream dependency timeout
    case QueueFailure = 'queue_failure'; // retryable — enqueueing the deferred job itself failed
    case Validation    = 'validation';    // non-retryable — bad step config, retrying won't fix it
    case Timeout       = 'timeout';       // non-retryable by default — a deferred step exceeded its allowed wait
    case Unknown        = 'unknown';       // non-retryable by default (safe default)

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Transient, self::QueueFailure => true,
            default => false,
        };
    }
}

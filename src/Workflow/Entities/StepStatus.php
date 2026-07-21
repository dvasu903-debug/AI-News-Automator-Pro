<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

/**
 * A single WorkflowStepResult's lifecycle. Running -> Deferred is the
 * async path (Decision 3): a step that returned ActionResult::deferred()
 * sits in Deferred until the queue-completion listener resumes it.
 */
enum StepStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Deferred  = 'deferred';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Skipped   = 'skipped';
    case TimedOut  = 'timed_out';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Skipped, self::TimedOut], true);
    }
}

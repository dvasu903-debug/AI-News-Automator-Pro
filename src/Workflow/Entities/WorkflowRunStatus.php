<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

/**
 * A WorkflowRun's lifecycle. Valid transitions (enforced by
 * WorkflowRunner, not by this enum or the repository — matching the
 * project's established "state guards live in the orchestrator" pattern
 * from ResearchSessionManager, per the Module 6 RC audit's Issue 1
 * lesson):
 *
 *   Pending -> Running
 *   Running -> AwaitingApproval | Completed | Failed
 *   AwaitingApproval -> Running (approved) | Failed (rejected)
 *   Failed -> RollingBack -> Failed (terminal, rollback outcome recorded on steps)
 *
 * Completed and Failed are terminal — no transition out of either.
 */
enum WorkflowRunStatus: string
{
    case Pending          = 'pending';
    case Running          = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case RollingBack      = 'rolling_back';
    case Completed        = 'completed';
    case Failed           = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}

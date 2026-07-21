<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Actions;

use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * A near-marker action: signals to WorkflowRunner that this step
 * requires human approval before the walk continues. Deliberately has
 * no side effects of its own (no repository writes, no events) — the
 * Runner is the single place that creates the Approval record and
 * dispatches ApprovalRequestedEvent, matching "Runner owns the
 * workflow" and keeping the audit trail centralized in one place
 * rather than scattered across whichever action happens to trigger it.
 */
final class ApprovalGateAction implements ActionInterface
{
    public function type(): string
    {
        return 'approval_gate';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        return ActionResult::awaitingApproval();
    }
}

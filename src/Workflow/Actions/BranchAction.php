<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Actions;

use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * Per §2.4: branching (if/else/switch) is expressed as data on a step's
 * `condition`/`next` fields and resolved entirely by
 * WorkflowRunner + ConditionEvaluator — it is NOT a real unit of
 * execution work. This class exists only so a workflow definition can
 * name an explicit "branch" step in its `steps` array for readability
 * (a no-op marker with a routing role, not business logic); it performs
 * no work and always succeeds immediately.
 */
final class BranchAction implements ActionInterface
{
    public function type(): string
    {
        return 'branch';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        return ActionResult::success();
    }
}

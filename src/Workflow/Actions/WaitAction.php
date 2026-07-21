<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Actions;

use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * A generic, module-agnostic "pause for N seconds" step. Synchronous —
 * short waits don't need the deferred/queue machinery; a workflow
 * needing a long pause should use a Scheduled trigger for the next
 * step instead of a long-blocking Wait, but nothing here enforces that
 * — it's a config-time responsibility of whoever authors the definition.
 */
final class WaitAction implements ActionInterface
{
    public function type(): string
    {
        return 'wait';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $seconds = (int) ($context->stepConfig['seconds'] ?? 0);

        if ($seconds > 0) {
            sleep($seconds);
        }

        return ActionResult::success(['waited_seconds' => $seconds]);
    }
}

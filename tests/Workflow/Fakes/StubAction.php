<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Fakes;

use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * A configurable test action: returns a queue of pre-programmed
 * ActionResult values (or throws a pre-programmed exception) on
 * successive calls, and records every context it was invoked with —
 * lets WorkflowRunnerTest exercise success, failure, deferred,
 * exception-then-retry-then-success, and exhausted-retry paths without
 * a real action implementation.
 */
final class StubAction implements ActionInterface
{
    /** @var list<ActionResult|\Throwable> */
    private array $queue;

    /** @var list<WorkflowRunContext> */
    public array $calls = [];

    /**
     * @param list<ActionResult|\Throwable> $queue
     */
    public function __construct(private readonly string $actionType, array $queue)
    {
        $this->queue = $queue;
    }

    public function type(): string
    {
        return $this->actionType;
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $this->calls[] = $context;

        $next = array_shift($this->queue) ?? ActionResult::success();

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

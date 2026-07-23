<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Fakes;

use AINewsAutomator\Workflow\Contracts\RollbackableActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\RollbackResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use AINewsAutomator\Workflow\Entities\WorkflowStepResult;

/**
 * A rollbackable test action, always succeeding on execute() and
 * recording every rollback() call — lets WorkflowRunnerTest verify
 * the reverse-order best-effort rollback walk (§2.5).
 */
final class StubRollbackableAction implements RollbackableActionInterface
{
    /** @var list<WorkflowStepResult> */
    public array $rollbackCalls = [];

    public function __construct(
        private readonly string $actionType,
        private readonly ?RollbackResult $rollbackResult = null,
    ) {
    }

    public function type(): string
    {
        return $this->actionType;
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        return ActionResult::success(['step' => $context->stepKey]);
    }

    public function rollback(WorkflowStepResult $result): RollbackResult
    {
        $this->rollbackCalls[] = $result;

        return $this->rollbackResult ?? RollbackResult::rolledBack();
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

use AINewsAutomator\Workflow\DTO\RollbackResult;
use AINewsAutomator\Workflow\Entities\WorkflowStepResult;

/**
 * Opt-in extension of ActionInterface for actions that can undo their
 * own effects. Not every action can be reversed (e.g. a sent
 * notification) — those simply don't implement this interface, and the
 * Runner treats them as NotReversible automatically. See §2.5.
 */
interface RollbackableActionInterface extends ActionInterface
{
    /**
     * Best-effort rollback of a previously-completed step. Returns one
     * of RolledBack / RollbackFailed / NotReversible — never throws for
     * an expected "cannot be undone" case (return NotReversible instead).
     */
    public function rollback(WorkflowStepResult $result): RollbackResult;
}

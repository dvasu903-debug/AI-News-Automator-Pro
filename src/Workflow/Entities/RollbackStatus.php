<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

/**
 * Persisted on WorkflowStepResult once rollback has been attempted for
 * that step. Null (not stored as an enum case) means "rollback not
 * attempted" — distinct from any of these three outcomes.
 */
enum RollbackStatus: string
{
    case RolledBack     = 'rolled_back';
    case RollbackFailed = 'rollback_failed';
    case NotReversible  = 'not_reversible';
}

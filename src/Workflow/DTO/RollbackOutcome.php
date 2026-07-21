<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

enum RollbackOutcome: string
{
    case RolledBack     = 'rolled_back';
    case RollbackFailed = 'rollback_failed';
    case NotReversible  = 'not_reversible';
}

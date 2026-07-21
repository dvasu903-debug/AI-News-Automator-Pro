<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\DTO;

enum ActionOutcome: string
{
    case Success          = 'success';
    case Failure          = 'failure';
    case Deferred         = 'deferred';

    /**
     * Not in the original design doc's ActionResult sketch — added
     * during Build to make §2.3 step 7 ("An ApprovalGateAction
     * transitions the run to AwaitingApproval and halts the walk")
     * mechanically possible: the design doc describes the required
     * behavior but never specifies how an action signals it, and
     * ActionResult's only other outcomes (success/failure/deferred)
     * can't express it. Documented here rather than silently added.
     */
    case AwaitingApproval = 'awaiting_approval';
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

/**
 * The four approval states required. Pending is the only non-terminal
 * state; Approved/Rejected/Expired are terminal and, once recorded,
 * immutable (§ Part 5 — "no update path on a resolved approval record").
 */
enum ApprovalStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired  = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}

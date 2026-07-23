<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\DTO;

/**
 * Mirrors Workflow\DTO\ActionOutcome's shape for the same reason: a
 * closed set of outcomes a caller branches on once, rather than boolean
 * flags or string comparisons scattered across call sites.
 */
enum PublishOutcome
{
    case Published;
    case Scheduled;
    case Unpublished;
    case Archived;
    case Rejected;
    case Failed;
}

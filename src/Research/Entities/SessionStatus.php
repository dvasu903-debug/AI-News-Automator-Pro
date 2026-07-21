<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

/**
 * A ResearchSession's lifecycle. gathering -> analyzing -> completed is
 * the normal path; abandoned is a manual/administrative terminal state.
 * Evidence can only be added while gathering (SessionStateException
 * enforces this — see SessionRepository/ResearchSessionManager).
 */
enum SessionStatus: string
{
    case Gathering = 'gathering';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
}

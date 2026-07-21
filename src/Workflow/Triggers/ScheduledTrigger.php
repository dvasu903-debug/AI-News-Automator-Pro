<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Triggers;

use AINewsAutomator\Workflow\Contracts\TriggerInterface;

/**
 * Marker for workflows started by WorkflowScheduler's WP-Cron tick
 * (see Scheduling\WorkflowScheduler) based on a definition's
 * trigger.config.cron_interval. Carries no logic of its own — the
 * scheduler owns the actual timing/enqueue behavior, matching
 * Sources\Scheduling\SourceSyncScheduler's precedent.
 */
final class ScheduledTrigger implements TriggerInterface
{
    public function type(): string
    {
        return 'scheduled';
    }
}

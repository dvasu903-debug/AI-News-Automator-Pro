<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Triggers;

use AINewsAutomator\Workflow\Contracts\TriggerInterface;

/**
 * Marker for workflows started directly (admin UI "Run now" or REST
 * POST with triggered_by=manual). No scheduling/subscription logic of
 * its own — WorkflowRunner::run() is called directly by the caller.
 */
final class ManualTrigger implements TriggerInterface
{
    public function type(): string
    {
        return 'manual';
    }
}

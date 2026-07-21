<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Triggers;

use AINewsAutomator\Workflow\Contracts\TriggerInterface;

/**
 * Marker for workflows started via the REST API trigger endpoint. Per
 * the Part 5 security review, an API-triggered run goes through the
 * exact same RestSecurityMiddleware::requireAbility() authorization
 * path as every other write endpoint — this class carries no special
 * trust and performs no authorization itself, WorkflowController does.
 */
final class ApiTrigger implements TriggerInterface
{
    public function type(): string
    {
        return 'api';
    }
}

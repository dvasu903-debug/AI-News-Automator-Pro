<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\AbstractEvent;

/**
 * Base for the Workflow module's domain events. Extends Core's
 * AbstractEvent — same pattern as every prior module's event base.
 */
abstract class WorkflowEvent extends AbstractEvent
{
}

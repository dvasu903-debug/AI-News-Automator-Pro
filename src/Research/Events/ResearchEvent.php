<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Events;

use AINewsAutomator\Core\Events\AbstractEvent;

/**
 * Base for the Research module's domain events. Extends Core's
 * AbstractEvent — same pattern as every prior module's event base.
 */
abstract class ResearchEvent extends AbstractEvent
{
}

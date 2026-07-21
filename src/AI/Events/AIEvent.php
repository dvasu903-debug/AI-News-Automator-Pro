<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Events;

use AINewsAutomator\Core\Events\AbstractEvent;

/**
 * Base for the AI module's domain events. Extends Core's AbstractEvent
 * so every event carries the standard metadata envelope and flows
 * through Core's EventDispatcher unchanged — same pattern as Security's
 * and Storage's event bases.
 */
abstract class AIEvent extends AbstractEvent
{
}

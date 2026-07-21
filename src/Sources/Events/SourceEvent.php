<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Events;

use AINewsAutomator\Core\Events\AbstractEvent;

/**
 * Base for Sources' domain events — extends Core's AbstractEvent, the
 * same pattern Security/Storage/AI all used (4th module to follow it).
 */
abstract class SourceEvent extends AbstractEvent
{
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Events;

use AINewsAutomator\Core\Events\AbstractEvent;

/**
 * Base for the Publishing module's domain events. Extends Core's
 * AbstractEvent — same pattern as every prior module's event base.
 */
abstract class PublishingEvent extends AbstractEvent
{
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Events;

use AINewsAutomator\Core\Events\AbstractEvent;
use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Base for all security events. Extends Core's AbstractEvent so every
 * security event carries the standard metadata envelope (event id,
 * timestamp, correlation id, source module, context) and participates in
 * the Core EventDispatcher unchanged — the dispatcher stays security-
 * agnostic; these events simply flow through it.
 */
abstract class SecurityEvent extends AbstractEvent
{
    public function __construct(EventMetadata $metadata)
    {
        parent::__construct($metadata);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when a new research session begins gathering evidence.
 */
final class ResearchSessionStartedEvent extends ResearchEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sessionId,
        public readonly string $topic,
    ) {
        parent::__construct($metadata);
    }
}

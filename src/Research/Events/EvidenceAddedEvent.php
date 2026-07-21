<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when a piece of Evidence is recorded against a session.
 */
final class EvidenceAddedEvent extends ResearchEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sessionId,
        public readonly int $evidenceId,
        public readonly string $sourceUrl,
    ) {
        parent::__construct($metadata);
    }
}

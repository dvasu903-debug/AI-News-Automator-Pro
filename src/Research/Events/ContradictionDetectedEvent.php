<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when AiContradictionDetector flags a conflict between two
 * claims within the same session.
 */
final class ContradictionDetectedEvent extends ResearchEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sessionId,
        public readonly int $claimAId,
        public readonly int $claimBId,
        public readonly string $severity,
    ) {
        parent::__construct($metadata);
    }
}

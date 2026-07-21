<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when a claim is extracted and persisted during analysis.
 */
final class ClaimExtractedEvent extends ResearchEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sessionId,
        public readonly int $claimId,
        public readonly string $statement,
    ) {
        parent::__construct($metadata);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Research's hand-off point — mirrors ItemDiscoveredEvent's role for
 * Sources. This is what a future Publishing (Module 8) or Workflow
 * (Module 7) listener subscribes to, resolving the full ResearchSummary
 * via SessionRepositoryInterface::summarize($sessionId) rather than this
 * event carrying the whole summary inline (events stay small; the
 * summary is fetched through the one sanctioned read path).
 */
final class ResearchSessionCompletedEvent extends ResearchEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sessionId,
        public readonly int $claimCount,
        public readonly float $overallConfidence,
        public readonly bool $hasBlockingContradictions,
    ) {
        parent::__construct($metadata);
    }
}

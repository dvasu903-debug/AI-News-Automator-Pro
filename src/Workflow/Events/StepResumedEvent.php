<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/** Recorded when a deferred step's queue job completes and the run resumes (Decision 3). */
final class StepResumedEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly int $queueJobId,
        public readonly bool $succeeded,
    ) {
        parent::__construct($metadata);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/** Recorded when a step opts into async completion (Decision 3). */
final class StepDeferredEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly int $queueJobId,
    ) {
        parent::__construct($metadata);
    }
}

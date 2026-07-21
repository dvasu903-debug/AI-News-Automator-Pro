<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class StepStartedEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly string $actionType,
    ) {
        parent::__construct($metadata);
    }
}

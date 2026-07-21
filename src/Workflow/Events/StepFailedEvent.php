<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class StepFailedEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly string $error,
        public readonly bool $willRetry,
    ) {
        parent::__construct($metadata);
    }
}

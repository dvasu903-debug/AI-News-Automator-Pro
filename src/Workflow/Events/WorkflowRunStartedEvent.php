<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class WorkflowRunStartedEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
        public readonly string $workflowKey,
        public readonly int $version,
        public readonly string $triggeredBy,
    ) {
        parent::__construct($metadata);
    }
}

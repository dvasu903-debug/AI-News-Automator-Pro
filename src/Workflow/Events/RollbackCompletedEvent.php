<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class RollbackCompletedEvent extends WorkflowEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $runId,
    ) {
        parent::__construct($metadata);
    }
}

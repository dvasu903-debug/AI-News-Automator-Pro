<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class WorkflowSavedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $workflowId,
        public readonly string $vertical,
        public readonly bool $wasCreated,
    ) {
        parent::__construct($metadata);
    }
}

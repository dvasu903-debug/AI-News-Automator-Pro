<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class JobEnqueuedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $jobId,
        public readonly string $jobType,
        public readonly int $priority,
    ) {
        parent::__construct($metadata);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class JobCompletedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $jobId,
        public readonly string $jobType,
    ) {
        parent::__construct($metadata);
    }
}

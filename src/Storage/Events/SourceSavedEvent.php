<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class SourceSavedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sourceId,
        public readonly string $type,
        public readonly bool $wasCreated,
    ) {
        parent::__construct($metadata);
    }
}

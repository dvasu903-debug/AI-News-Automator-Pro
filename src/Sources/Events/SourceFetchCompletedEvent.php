<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class SourceFetchCompletedEvent extends SourceEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sourceId,
        public readonly int $itemsDiscovered,
        public readonly int $itemsDuplicate,
        public readonly float $durationMs,
    ) {
        parent::__construct($metadata);
    }
}

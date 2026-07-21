<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class SourceFetchFailedEvent extends SourceEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sourceId,
        public readonly string $errorMessage,
    ) {
        parent::__construct($metadata);
    }
}

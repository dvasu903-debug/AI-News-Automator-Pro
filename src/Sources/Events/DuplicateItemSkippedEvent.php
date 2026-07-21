<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class DuplicateItemSkippedEvent extends SourceEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sourceId,
        public readonly string $fingerprint,
        public readonly string $url,
    ) {
        parent::__construct($metadata);
    }
}

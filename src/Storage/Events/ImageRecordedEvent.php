<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class ImageRecordedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $imageId,
        public readonly ?int $articleId,
        public readonly string $source,
    ) {
        parent::__construct($metadata);
    }
}

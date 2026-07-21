<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class ArticleDraftCreatedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
        public readonly ?string $sourceUrl,
    ) {
        parent::__construct($metadata);
    }
}

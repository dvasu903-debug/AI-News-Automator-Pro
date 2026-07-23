<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class PublishingFailedEvent extends PublishingEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
        public readonly string $error,
    ) {
        parent::__construct($metadata);
    }
}

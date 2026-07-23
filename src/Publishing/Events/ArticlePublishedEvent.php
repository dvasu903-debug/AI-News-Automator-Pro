<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class ArticlePublishedEvent extends PublishingEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
    ) {
        parent::__construct($metadata);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\EventMetadata;

final class ArticleApprovedEvent extends StorageEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
        public readonly int $approvedByUserId,
    ) {
        parent::__construct($metadata);
    }
}

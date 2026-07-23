<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when an Action rejects a publish/schedule attempt before it
 * ever reaches PublisherInterface — an editorial policy violation or an
 * approval_mode gate, not a system error (see PublishingFailedEvent for
 * that case).
 */
final class PublishingRejectedEvent extends PublishingEvent
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
        public readonly array $reasons,
    ) {
        parent::__construct($metadata);
    }
}

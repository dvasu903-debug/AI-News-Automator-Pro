<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when GenerateAction creates a new draft from a research
 * session's summary.
 */
final class DraftGeneratedEvent extends PublishingEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
        public readonly int $researchSessionId,
    ) {
        parent::__construct($metadata);
    }
}

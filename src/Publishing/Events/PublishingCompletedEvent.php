<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Dispatched when PostProcessAction finishes the AI-generation
 * pipeline's final step for a draft — the pipeline's own end marker,
 * distinct from ArticlePublishedEvent (which fires only once an editor
 * or a later publish step actually makes the post live).
 */
final class PublishingCompletedEvent extends PublishingEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $postId,
    ) {
        parent::__construct($metadata);
    }
}

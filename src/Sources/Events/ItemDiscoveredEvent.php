<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Events;

use AINewsAutomator\Core\Events\EventMetadata;

/**
 * Emitted for every newly-discovered, non-duplicate, validated item.
 * This is the hand-off point to future modules (Research/Pipeline) —
 * Module 5's own responsibility ends here; it does not itself decide
 * whether the item becomes an article.
 */
final class ItemDiscoveredEvent extends SourceEvent
{
    public function __construct(
        EventMetadata $metadata,
        public readonly int $sourceId,
        public readonly string $fingerprint,
        public readonly string $url,
        public readonly ?string $title,
    ) {
        parent::__construct($metadata);
    }
}

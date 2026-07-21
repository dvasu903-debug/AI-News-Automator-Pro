<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Events;

/**
 * Immutable envelope of metadata attached to every dispatched event.
 *
 * Kept as a separate object (rather than loose properties on AbstractEvent)
 * so that: (a) an event's own domain payload stays cleanly separated from
 * cross-cutting metadata, and (b) a listener that only cares about
 * metadata (e.g. a generic event-audit listener) can accept the metadata
 * without depending on any concrete event type.
 */
final class EventMetadata
{
    /**
     * @param string $eventId       Unique per emission (UUID).
     * @param int    $timestamp     Unix timestamp of emission.
     * @param string $correlationId Ties this event to the logical unit of work that emitted it.
     * @param string $sourceModule  The module that emitted the event, e.g. "Pipeline".
     * @param array<string, mixed> $context Optional free-form contextual data.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly int $timestamp,
        public readonly string $correlationId,
        public readonly string $sourceModule,
        public readonly array $context = [],
    ) {
    }
}

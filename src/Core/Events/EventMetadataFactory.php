<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Events;

use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Core\Support\Uuid;

/**
 * Produces EventMetadata, pulling the current correlation ID from the
 * shared CorrelationContext and generating a fresh event ID + timestamp
 * per call.
 *
 * Injected into whichever module emits events, so no module hand-rolls
 * metadata construction or reaches for a global to get the correlation
 * ID — keeping the "every event has consistent metadata" guarantee in
 * one place. The dispatcher does not depend on this; emitters do.
 */
final class EventMetadataFactory
{
    public function __construct(private readonly CorrelationContext $correlation)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function create(string $sourceModule, array $context = []): EventMetadata
    {
        return new EventMetadata(
            eventId: Uuid::v4(),
            timestamp: time(),
            correlationId: $this->correlation->id(),
            sourceModule: $sourceModule,
            context: $context,
        );
    }
}

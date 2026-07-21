<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Events;

use AINewsAutomator\Core\Contracts\StoppableEventInterface;

/**
 * Base class for every event in the plugin. Carries an EventMetadata
 * envelope (id, timestamp, correlation ID, source module, optional
 * context) and supports propagation stopping.
 *
 * Concrete events extend this and add their own domain payload as
 * readonly constructor properties. They construct their metadata via
 * the protected makeMetadata() helper, which every module uses the same
 * way so metadata is populated consistently regardless of which module
 * emitted the event. The dispatcher itself stays entirely metadata-
 * agnostic — it never reads these fields — which keeps it loosely coupled
 * and reusable for any object, exactly as required.
 */
abstract class AbstractEvent implements StoppableEventInterface
{
    private bool $propagationStopped = false;

    private EventMetadata $metadata;

    public function __construct(EventMetadata $metadata)
    {
        $this->metadata = $metadata;
    }

    public function metadata(): EventMetadata
    {
        return $this->metadata;
    }

    public function eventId(): string
    {
        return $this->metadata->eventId;
    }

    public function correlationId(): string
    {
        return $this->metadata->correlationId;
    }

    public function sourceModule(): string
    {
        return $this->metadata->sourceModule;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}

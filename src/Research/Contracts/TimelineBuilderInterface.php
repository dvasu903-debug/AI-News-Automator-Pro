<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\DTO\TimelineEntry;

/**
 * Builds a session's chronological timeline on demand from Evidence
 * dates — a computed view, never persisted separately.
 */
interface TimelineBuilderInterface
{
    /**
     * @return list<TimelineEntry> Chronologically ordered.
     */
    public function buildFor(int $sessionId): array;
}

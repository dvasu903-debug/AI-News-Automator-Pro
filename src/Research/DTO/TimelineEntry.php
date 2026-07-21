<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\DTO;

/**
 * One point on a session's derived timeline — built on demand from
 * Evidence/Claim dates by TimelineBuilder, never persisted separately
 * (the same "computed view, no redundant storage" discipline as
 * Sources' reputation scoring and AI's cost calculation).
 */
final class TimelineEntry
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $description,
        public readonly string $sourceUrl,
    ) {
    }
}

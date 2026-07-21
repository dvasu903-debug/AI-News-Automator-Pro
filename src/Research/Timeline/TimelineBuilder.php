<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Timeline;

use AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface;
use AINewsAutomator\Research\Contracts\TimelineBuilderInterface;
use AINewsAutomator\Research\DTO\TimelineEntry;

/**
 * Builds a session's timeline on demand from Evidence dates — never
 * persisted separately (the same "computed view" discipline as Sources'
 * reputation scoring and AI's cost calculation). Only evidence with a
 * known publishedAt contributes a timeline entry; undated evidence is
 * silently excluded rather than guessed at.
 */
final class TimelineBuilder implements TimelineBuilderInterface
{
    public function __construct(private readonly EvidenceRepositoryInterface $evidence)
    {
    }

    public function buildFor(int $sessionId): array
    {
        $evidence = $this->evidence->forSession($sessionId);

        $entries = [];
        foreach ($evidence as $item) {
            if ($item->publishedAt === null) {
                continue;
            }

            $entries[] = new TimelineEntry(
                date: $item->publishedAt,
                description: $item->snippet ?? $item->sourceUrl,
                sourceUrl: $item->sourceUrl,
            );
        }

        usort($entries, static fn (TimelineEntry $a, TimelineEntry $b): int => $a->date <=> $b->date);

        return $entries;
    }
}

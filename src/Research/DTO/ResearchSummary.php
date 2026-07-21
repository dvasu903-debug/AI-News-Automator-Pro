<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\DTO;

use AINewsAutomator\Research\Entities\Contradiction;
use AINewsAutomator\Research\Entities\ExtractedEntity;

/**
 * THE authoritative output contract of the Research Engine — per the
 * approved design, this becomes the required input contract for all
 * future Publishing work (Module 8). Publishing must depend on this DTO
 * and on Research\Contracts\SessionRepositoryInterface::summarize()
 * ONLY — never on Research's internal tables or repositories directly,
 * the same read-only-contract discipline every prior module's public
 * interface has followed.
 *
 * Deliberately does NOT include a "readyToPublish" boolean — that is an
 * editorial POLICY decision (minimum confidence, disclosure
 * requirements, etc.), which belongs to Publishing's own
 * EditorialPolicyInterface (Module 8, future), not to Research. Research
 * reports facts about what it found; it does not decide what's
 * publishable — consistent with "Research must never generate
 * publishable content" extending to "Research must never decide
 * publishability" either.
 */
final class ResearchSummary
{
    /**
     * @param list<ClaimSummary> $claims
     * @param list<ExtractedEntity> $entities
     * @param list<Contradiction> $unresolvedContradictions Only unresolved ones — resolved contradictions are historical record, not a live concern for a consumer.
     * @param list<TimelineEntry> $timeline
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly string $correlationId,
        public readonly string $topic,
        public readonly ?string $topicCluster,
        public readonly array $claims,
        public readonly array $entities,
        public readonly array $unresolvedContradictions,
        public readonly DiversityReport $sourceDiversity,
        public readonly array $timeline,
        public readonly float $overallConfidence,
        public readonly \DateTimeImmutable $generatedAt,
    ) {
    }

    public function hasBlockingContradictions(): bool
    {
        foreach ($this->unresolvedContradictions as $contradiction) {
            if ($contradiction->severity->blocksPublishing()) {
                return true;
            }
        }

        return false;
    }

    public function citationCount(): int
    {
        $count = 0;
        foreach ($this->claims as $claimSummary) {
            $count += count($claimSummary->citations);
        }

        return $count;
    }
}

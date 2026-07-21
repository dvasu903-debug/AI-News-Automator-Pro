<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Session;

use AINewsAutomator\Research\Contracts\CitationRepositoryInterface;
use AINewsAutomator\Research\Contracts\ClaimRepositoryInterface;
use AINewsAutomator\Research\Contracts\ContradictionRepositoryInterface;
use AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface;
use AINewsAutomator\Research\Contracts\ExtractedEntityRepositoryInterface;
use AINewsAutomator\Research\Contracts\SourceDiversityAnalyzerInterface;
use AINewsAutomator\Research\Contracts\TimelineBuilderInterface;
use AINewsAutomator\Research\DTO\ClaimSummary;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * Assembles the authoritative ResearchSummary DTO by reading across every
 * Research repository — deliberately a SEPARATE class from
 * SessionRepository (which SessionRepository::summarize() delegates to)
 * rather than cramming multi-table assembly logic into what would
 * otherwise be a simple CRUD repository. Single responsibility: this
 * class only reads and assembles, never writes.
 */
final class ResearchSummaryBuilder
{
    public function __construct(
        private readonly ClaimRepositoryInterface $claims,
        private readonly CitationRepositoryInterface $citations,
        private readonly ExtractedEntityRepositoryInterface $entities,
        private readonly ContradictionRepositoryInterface $contradictions,
        private readonly EvidenceRepositoryInterface $evidence,
        private readonly SourceDiversityAnalyzerInterface $diversityAnalyzer,
        private readonly TimelineBuilderInterface $timelineBuilder,
    ) {
    }

    public function build(ResearchSession $session): ResearchSummary
    {
        $sessionId = (int) $session->id;

        $claims = $this->claims->forSession($sessionId);
        $claimSummaries = array_map(
            fn ($claim) => new ClaimSummary($claim, $this->citations->forClaim((int) $claim->id)),
            $claims
        );

        return new ResearchSummary(
            sessionId: $sessionId,
            correlationId: $session->correlationId,
            topic: $session->topic,
            topicCluster: $session->topicCluster,
            claims: $claimSummaries,
            entities: $this->entities->forSession($sessionId),
            unresolvedContradictions: $this->contradictions->forSession($sessionId, unresolvedOnly: true),
            sourceDiversity: $this->diversityAnalyzer->analyze($this->evidence->forSession($sessionId)),
            timeline: $this->timelineBuilder->buildFor($sessionId),
            overallConfidence: $session->confidenceScore ?? 0.0,
            generatedAt: EntityDates::now(),
        );
    }
}

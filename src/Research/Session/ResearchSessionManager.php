<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Session;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Research\Contracts\CitationRepositoryInterface;
use AINewsAutomator\Research\Contracts\ClaimExtractorInterface;
use AINewsAutomator\Research\Contracts\ClaimRepositoryInterface;
use AINewsAutomator\Research\Contracts\ContradictionDetectorInterface;
use AINewsAutomator\Research\Contracts\ContradictionRepositoryInterface;
use AINewsAutomator\Research\Contracts\EntityExtractorInterface;
use AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface;
use AINewsAutomator\Research\Contracts\ExtractedEntityRepositoryInterface;
use AINewsAutomator\Research\Contracts\ResearchConfidenceInterface;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Contracts\TopicClustererInterface;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Research\Entities\EvidenceRelationship;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Events\ClaimExtractedEvent;
use AINewsAutomator\Research\Events\ContradictionDetectedEvent;
use AINewsAutomator\Research\Events\EvidenceAddedEvent;
use AINewsAutomator\Research\Events\ResearchSessionCompletedEvent;
use AINewsAutomator\Research\Events\ResearchSessionStartedEvent;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * The single orchestration entry point for research work — mirrors
 * AIManager's role as "the one thing business logic depends on." Ties
 * together extraction, scoring, contradiction detection, and clustering
 * without any of those individual services knowing about each other.
 *
 * Deliberately splits CHEAP evidence recording (addEvidence — no AI
 * call) from EXPENSIVE analysis (analyzeSession — the AI-driven pass),
 * so multiple pieces of evidence can be gathered before a single batched
 * analysis, not one AI call per evidence item as it arrives.
 */
final class ResearchSessionManager
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly EvidenceRepositoryInterface $evidence,
        private readonly ClaimRepositoryInterface $claims,
        private readonly CitationRepositoryInterface $citations,
        private readonly ExtractedEntityRepositoryInterface $entities,
        private readonly ContradictionRepositoryInterface $contradictions,
        private readonly ClaimExtractorInterface $claimExtractor,
        private readonly EntityExtractorInterface $entityExtractor,
        private readonly ContradictionDetectorInterface $contradictionDetector,
        private readonly ResearchConfidenceInterface $confidenceScorer,
        private readonly TopicClustererInterface $topicClusterer,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly MetricsRepositoryInterface $metrics,
        private readonly LoggerInterface $logger,
        private readonly CorrelationContext $correlation,
    ) {
    }

    public function startSession(string $topic, string $vertical = 'news', ?string $correlationId = null): int
    {
        $session = new ResearchSession(
            id: null,
            correlationId: $correlationId ?? $this->correlation->id(),
            topic: $topic,
            vertical: $vertical,
            status: SessionStatus::Gathering,
            topicCluster: null,
            confidenceScore: null,
            createdAt: EntityDates::now(),
            updatedAt: EntityDates::now(),
            completedAt: null,
        );

        $sessionId = $this->sessions->save($session);

        $this->events->dispatch(new ResearchSessionStartedEvent(
            $this->metadataFactory->create('Research', ['session_id' => $sessionId]),
            sessionId: $sessionId,
            topic: $topic,
        ));

        $this->metrics->increment('research.sessions_started');

        return $sessionId;
    }

    /**
     * @throws SessionStateException If the session is not currently Gathering.
     */
    public function addEvidence(
        int $sessionId,
        string $sourceUrl,
        string $sourceType,
        string $domain,
        ?float $credibilityScore,
        ?string $snippet,
        ?\DateTimeImmutable $publishedAt,
    ): int {
        $session = $this->requireSession($sessionId);

        if ($session->status !== SessionStatus::Gathering) {
            throw SessionStateException::notGathering($sessionId, $session->status->value);
        }

        $evidence = new Evidence(
            id: null,
            sessionId: $sessionId,
            sourceUrl: $sourceUrl,
            sourceType: $sourceType,
            domain: $domain,
            credibilityScore: $credibilityScore,
            snippet: $snippet,
            publishedAt: $publishedAt,
            createdAt: EntityDates::now(),
        );

        $evidenceId = $this->evidence->record($evidence);

        $this->events->dispatch(new EvidenceAddedEvent(
            $this->metadataFactory->create('Research', ['session_id' => $sessionId]),
            sessionId: $sessionId,
            evidenceId: $evidenceId,
            sourceUrl: $sourceUrl,
        ));

        return $evidenceId;
    }

    /**
     * Runs the full AI-driven analysis pass: extract claims and entities
     * from every piece of evidence, detect contradictions incrementally,
     * score confidence, cluster the topic, and complete the session.
     *
     * @throws SessionStateException If the session is not currently Gathering.
     */
    public function analyzeSession(int $sessionId): ResearchSummary
    {
        $session = $this->requireSession($sessionId);

        if ($session->status !== SessionStatus::Gathering) {
            throw SessionStateException::notGathering($sessionId, $session->status->value);
        }

        $session = $session->withStatus(SessionStatus::Analyzing);
        $this->sessions->save($session);

        $evidenceItems = $this->evidence->forSession($sessionId);
        $existingClaims = [];

        foreach ($evidenceItems as $evidenceItem) {
            $existingClaims = $this->processEvidence($sessionId, $evidenceItem, $existingClaims);
        }

        $overallConfidence = $this->confidenceScorer->scoreSession($existingClaims);
        $topicCluster = $this->topicClusterer->clusterFor($session->topic, $this->entities->forSession($sessionId));

        $session = $session
            ->withStatus(SessionStatus::Completed)
            ->withConfidenceScore($overallConfidence);

        if ($topicCluster !== null) {
            $session = $session->withTopicCluster($topicCluster);
        }

        $this->sessions->save($session);

        $summary = $this->sessions->summarize($sessionId);

        $this->events->dispatch(new ResearchSessionCompletedEvent(
            $this->metadataFactory->create('Research', ['session_id' => $sessionId]),
            sessionId: $sessionId,
            claimCount: count($summary->claims),
            overallConfidence: $overallConfidence,
            hasBlockingContradictions: $summary->hasBlockingContradictions(),
        ));

        $this->metrics->increment('research.sessions_completed');
        $this->metrics->record('research.confidence', (int) round($overallConfidence * 100));

        return $summary;
    }

    /**
     * @throws SessionStateException If the session is already Completed or Abandoned —
     *     a terminal state must not be silently overwritten (see Release-Candidate
     *     Verification Report, Issue 1).
     */
    public function abandonSession(int $sessionId): void
    {
        $session = $this->requireSession($sessionId);

        if ($session->status === SessionStatus::Completed || $session->status === SessionStatus::Abandoned) {
            throw SessionStateException::invalidTransition($sessionId, $session->status->value, SessionStatus::Abandoned->value);
        }

        $this->sessions->save($session->withStatus(SessionStatus::Abandoned));
        $this->metrics->increment('research.sessions_abandoned');
    }

    /**
     * @param list<Claim> $existingClaims
     * @return list<Claim> Updated running list, including this evidence's newly extracted claims.
     */
    private function processEvidence(int $sessionId, Evidence $evidenceItem, array $existingClaims): array
    {
        foreach ($this->entityExtractor->extract($evidenceItem) as $entityData) {
            $this->entities->recordOrIncrement($sessionId, $entityData->name, $entityData->entityType);
        }

        foreach ($this->claimExtractor->extract($evidenceItem) as $claimData) {
            $claim = new Claim(
                id: null,
                sessionId: $sessionId,
                statement: $claimData->statement,
                confidenceScore: 0.0,
                status: ClaimStatus::Unverified,
                createdAt: EntityDates::now(),
            );

            $claimId = $this->claims->record($claim);
            $claim = new Claim($claimId, $sessionId, $claim->statement, $claim->confidenceScore, $claim->status, $claim->createdAt);

            $this->claims->linkEvidence($claimId, (int) $evidenceItem->id, EvidenceRelationship::Supports);

            $citation = new Citation(
                id: null,
                claimId: $claimId,
                evidenceId: (int) $evidenceItem->id,
                citationText: sprintf('%s. Retrieved from %s.', $evidenceItem->domain, $evidenceItem->sourceUrl),
                createdAt: EntityDates::now(),
            );
            $this->citations->record($citation);

            $this->events->dispatch(new ClaimExtractedEvent(
                $this->metadataFactory->create('Research', ['session_id' => $sessionId]),
                sessionId: $sessionId,
                claimId: $claimId,
                statement: $claim->statement,
            ));

            $detectedContradictions = $this->contradictionDetector->detectFor($claim, $existingClaims);
            $hasContradiction = $detectedContradictions !== [];

            foreach ($detectedContradictions as $contradiction) {
                $this->contradictions->record($contradiction);

                $this->events->dispatch(new ContradictionDetectedEvent(
                    $this->metadataFactory->create('Research', ['session_id' => $sessionId]),
                    sessionId: $sessionId,
                    claimAId: $contradiction->claimAId,
                    claimBId: $contradiction->claimBId,
                    severity: $contradiction->severity->value,
                ));

                $this->metrics->increment('research.contradictions_detected');
            }

            $links = $this->claims->evidenceLinksFor($claimId);
            $confidence = $this->confidenceScorer->scoreClaim($claim, $links);
            $status = $hasContradiction ? ClaimStatus::Contradicted : ClaimStatus::Supported;

            $this->claims->updateStatusAndConfidence($claimId, $status, $confidence);
            $claim = $claim->withStatus($status)->withConfidenceScore($confidence);

            $existingClaims[] = $claim;
        }

        return $existingClaims;
    }

    private function requireSession(int $sessionId): ResearchSession
    {
        $session = $this->sessions->find($sessionId);

        if ($session === null) {
            throw new SessionStateException(sprintf('Research session %d not found.', $sessionId));
        }

        return $session;
    }
}

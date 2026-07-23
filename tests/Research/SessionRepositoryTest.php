<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Diversity\SourceDiversityAnalyzer;
use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Research\Repositories\CitationRepository;
use AINewsAutomator\Research\Repositories\ClaimRepository;
use AINewsAutomator\Research\Repositories\ContradictionRepository;
use AINewsAutomator\Research\Repositories\EvidenceRepository;
use AINewsAutomator\Research\Repositories\ExtractedEntityRepository;
use AINewsAutomator\Research\Repositories\SessionRepository;
use AINewsAutomator\Research\Session\ResearchSummaryBuilder;
use AINewsAutomator\Research\Timeline\TimelineBuilder;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 3. The most
 * valuable test in this batch: wires the REAL SessionRepository against
 * a REAL ResearchSummaryBuilder backed by REAL repositories (Claim,
 * Citation, ExtractedEntity, Contradiction, Evidence) — all against
 * FakeWpdb, not in-memory fakes. This is the first test anywhere in the
 * module that exercises the full real repository stack together.
 */
final class SessionRepositoryTest extends TestCase
{
    private SessionRepository $sessions;
    private ClaimRepository $claims;
    private CitationRepository $citations;
    private EvidenceRepository $evidence;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        foreach (['research_sessions', 'research_evidence', 'research_claims', 'research_claim_evidence', 'research_entities', 'research_citations', 'research_contradictions'] as $table) {
            $wpdb->createTable('wp_ana_' . $table);
        }

        $connection = new Connection();
        $this->claims = new ClaimRepository($connection);
        $this->citations = new CitationRepository($connection);
        $entities = new ExtractedEntityRepository($connection);
        $contradictions = new ContradictionRepository($connection);
        $this->evidence = new EvidenceRepository($connection);

        $summaryBuilder = new ResearchSummaryBuilder(
            $this->claims,
            $this->citations,
            $entities,
            $contradictions,
            $this->evidence,
            new SourceDiversityAnalyzer(),
            new TimelineBuilder($this->evidence),
        );

        $this->sessions = new SessionRepository($connection, $summaryBuilder);
    }

    private function session(string $topic = 'Test Topic', SessionStatus $status = SessionStatus::Gathering): ResearchSession
    {
        return new ResearchSession(null, 'corr-' . uniqid(), $topic, 'news', $status, null, null, EntityDates::now(), EntityDates::now(), null);
    }

    public function test_save_and_find(): void
    {
        $id = $this->sessions->save($this->session());

        $found = $this->sessions->find($id);

        $this->assertNotNull($found);
        $this->assertSame('Test Topic', $found->topic);
    }

    public function test_find_by_correlation_id(): void
    {
        $session = new ResearchSession(null, 'corr-fixed-123', 'Topic', 'news', SessionStatus::Gathering, null, null, EntityDates::now(), EntityDates::now(), null);
        $this->sessions->save($session);

        $found = $this->sessions->findByCorrelationId('corr-fixed-123');

        $this->assertNotNull($found);
        $this->assertSame('corr-fixed-123', $found->correlationId);
    }

    public function test_save_on_existing_session_updates_rather_than_duplicates(): void
    {
        $id = $this->sessions->save($this->session());
        $found = $this->sessions->find($id);

        $updated = $found->withStatus(SessionStatus::Analyzing);
        $this->sessions->save($updated);

        $refetched = $this->sessions->find($id);
        $this->assertSame(SessionStatus::Analyzing, $refetched->status);
    }

    public function test_by_status_filters_correctly(): void
    {
        $this->sessions->save($this->session(status: SessionStatus::Gathering));
        $this->sessions->save($this->session(status: SessionStatus::Completed));

        $completed = $this->sessions->byStatus(SessionStatus::Completed);

        $this->assertCount(1, $completed);
    }

    public function test_empty_topic_fails_validation(): void
    {
        $this->expectException(ValidationException::class);
        $this->sessions->save($this->session(topic: ''));
    }

    public function test_summarize_throws_for_gathering_session(): void
    {
        $id = $this->sessions->save($this->session(status: SessionStatus::Gathering));

        $this->expectException(SessionStateException::class);
        $this->sessions->summarize($id);
    }

    public function test_summarize_throws_for_missing_session(): void
    {
        $this->expectException(SessionStateException::class);
        $this->sessions->summarize(99999);
    }

    public function test_summarize_assembles_claims_with_citations_end_to_end(): void
    {
        // The full real-repository-stack test: build a Completed session
        // with real Evidence, Claim, Citation rows and confirm
        // summarize() assembles them correctly through the real
        // ResearchSummaryBuilder — no fakes anywhere in this call chain.
        $sessionId = $this->sessions->save($this->session(status: SessionStatus::Gathering));

        $evidenceId = $this->evidence->record(new Evidence(null, $sessionId, 'https://x.test/a', 'rss', 'x.test', 0.9, 'snippet', null, EntityDates::now()));

        $claimId = $this->claims->record(new Claim(null, $sessionId, 'A well-supported claim.', 0.8, ClaimStatus::Supported, EntityDates::now()));
        $this->citations->record(new Citation(null, $claimId, $evidenceId, 'x.test. Retrieved from https://x.test/a.', EntityDates::now()));

        $completed = $this->sessions->find($sessionId)->withStatus(SessionStatus::Completed)->withConfidenceScore(0.8);
        $this->sessions->save($completed);

        $summary = $this->sessions->summarize($sessionId);

        $this->assertCount(1, $summary->claims);
        $this->assertSame('A well-supported claim.', $summary->claims[0]->claim->statement);
        $this->assertCount(1, $summary->claims[0]->citations);
        $this->assertSame(0.8, $summary->overallConfidence);
        $this->assertSame(1, $summary->sourceDiversity->totalEvidence);
    }
}

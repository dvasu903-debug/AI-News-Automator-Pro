<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\EvidenceRelationship;
use AINewsAutomator\Research\Repositories\ClaimRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 3 — see
 * EvidenceRepositoryTest's docblock for the testing approach rationale.
 */
final class ClaimRepositoryTest extends TestCase
{
    private ClaimRepository $repository;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->createTable('wp_ana_research_claims');
        $wpdb->createTable('wp_ana_research_claim_evidence');

        $this->repository = new ClaimRepository(new Connection());
    }

    private function claim(int $sessionId = 1, string $statement = 'A test claim.'): Claim
    {
        return new Claim(null, $sessionId, $statement, 0.5, ClaimStatus::Unverified, EntityDates::now());
    }

    public function test_record_and_find(): void
    {
        $id = $this->repository->record($this->claim());

        $found = $this->repository->find($id);

        $this->assertNotNull($found);
        $this->assertSame('A test claim.', $found->statement);
        $this->assertSame(ClaimStatus::Unverified, $found->status);
    }

    public function test_update_status_and_confidence(): void
    {
        $id = $this->repository->record($this->claim());

        $this->repository->updateStatusAndConfidence($id, ClaimStatus::Supported, 0.85);

        $found = $this->repository->find($id);
        $this->assertSame(ClaimStatus::Supported, $found->status);
        $this->assertSame(0.85, $found->confidenceScore);
    }

    public function test_for_session_returns_only_that_sessions_claims(): void
    {
        $this->repository->record($this->claim(sessionId: 1, statement: 'Session 1 claim.'));
        $this->repository->record($this->claim(sessionId: 2, statement: 'Session 2 claim.'));

        $results = $this->repository->forSession(1);

        $this->assertCount(1, $results);
        $this->assertSame('Session 1 claim.', $results[0]->statement);
    }

    public function test_link_evidence_and_retrieve_links(): void
    {
        $claimId = $this->repository->record($this->claim());

        $this->repository->linkEvidence($claimId, 42, EvidenceRelationship::Supports);
        $this->repository->linkEvidence($claimId, 43, EvidenceRelationship::Contradicts);

        $links = $this->repository->evidenceLinksFor($claimId);

        $this->assertCount(2, $links);
        $relationships = array_map(static fn ($l) => $l->relationship, $links);
        $this->assertContains(EvidenceRelationship::Supports, $relationships);
        $this->assertContains(EvidenceRelationship::Contradicts, $relationships);
    }

    public function test_evidence_links_scoped_to_correct_claim(): void
    {
        $claimA = $this->repository->record($this->claim(statement: 'Claim A.'));
        $claimB = $this->repository->record($this->claim(statement: 'Claim B.'));

        $this->repository->linkEvidence($claimA, 1, EvidenceRelationship::Supports);
        $this->repository->linkEvidence($claimB, 2, EvidenceRelationship::Supports);

        $linksForA = $this->repository->evidenceLinksFor($claimA);

        $this->assertCount(1, $linksForA);
        $this->assertSame(1, $linksForA[0]->evidenceId);
    }

    public function test_empty_statement_fails_validation(): void
    {
        $this->expectException(ValidationException::class);
        $this->repository->record($this->claim(statement: ''));
    }

    public function test_confidence_above_one_fails_validation(): void
    {
        $invalid = new Claim(null, 1, 'Statement.', 1.5, ClaimStatus::Unverified, EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }

    public function test_confidence_below_zero_fails_validation(): void
    {
        $invalid = new Claim(null, 1, 'Statement.', -0.5, ClaimStatus::Unverified, EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }
}

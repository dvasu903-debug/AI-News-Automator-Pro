<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimEvidenceLink;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\EvidenceRelationship;
use AINewsAutomator\Research\Scoring\CompositeConfidenceScorer;
use AINewsAutomator\Storage\Entities\EntityDates;
use PHPUnit\Framework\TestCase;

final class CompositeConfidenceScorerTest extends TestCase
{
    private function claim(): Claim
    {
        return new Claim(1, 1, 'Test statement', 0.0, ClaimStatus::Unverified, EntityDates::now());
    }

    private function link(EvidenceRelationship $relationship): ClaimEvidenceLink
    {
        return new ClaimEvidenceLink(null, 1, 1, $relationship, EntityDates::now());
    }

    public function test_no_evidence_scores_zero(): void
    {
        $scorer = new CompositeConfidenceScorer();
        $this->assertSame(0.0, $scorer->scoreClaim($this->claim(), []));
    }

    public function test_only_contradicting_evidence_scores_zero(): void
    {
        $scorer = new CompositeConfidenceScorer();
        $links = [$this->link(EvidenceRelationship::Contradicts)];

        $this->assertSame(0.0, $scorer->scoreClaim($this->claim(), $links));
    }

    public function test_more_supporting_evidence_increases_confidence(): void
    {
        $scorer = new CompositeConfidenceScorer();

        $oneSupport = $scorer->scoreClaim($this->claim(), [$this->link(EvidenceRelationship::Supports)]);
        $threeSupport = $scorer->scoreClaim($this->claim(), [
            $this->link(EvidenceRelationship::Supports),
            $this->link(EvidenceRelationship::Supports),
            $this->link(EvidenceRelationship::Supports),
        ]);

        $this->assertGreaterThan($oneSupport, $threeSupport);
    }

    public function test_confidence_never_exceeds_one(): void
    {
        $scorer = new CompositeConfidenceScorer();
        $manySupport = array_fill(0, 50, $this->link(EvidenceRelationship::Supports));

        $this->assertLessThanOrEqual(1.0, $scorer->scoreClaim($this->claim(), $manySupport));
    }

    public function test_contradiction_penalizes_supported_claim(): void
    {
        $scorer = new CompositeConfidenceScorer();

        $supportOnly = $scorer->scoreClaim($this->claim(), [
            $this->link(EvidenceRelationship::Supports),
            $this->link(EvidenceRelationship::Supports),
        ]);

        $supportPlusContradiction = $scorer->scoreClaim($this->claim(), [
            $this->link(EvidenceRelationship::Supports),
            $this->link(EvidenceRelationship::Supports),
            $this->link(EvidenceRelationship::Contradicts),
        ]);

        $this->assertLessThan($supportOnly, $supportPlusContradiction);
    }

    public function test_session_score_is_average_of_claim_scores(): void
    {
        $scorer = new CompositeConfidenceScorer();
        $claims = [
            new Claim(1, 1, 'a', 0.8, ClaimStatus::Supported, EntityDates::now()),
            new Claim(2, 1, 'b', 0.4, ClaimStatus::Supported, EntityDates::now()),
        ];

        $this->assertSame(0.6, $scorer->scoreSession($claims));
    }

    public function test_empty_session_scores_zero(): void
    {
        $scorer = new CompositeConfidenceScorer();
        $this->assertSame(0.0, $scorer->scoreSession([]));
    }
}

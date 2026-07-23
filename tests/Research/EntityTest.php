<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Storage\Entities\EntityDates;
use PHPUnit\Framework\TestCase;

final class EntityTest extends TestCase
{
    public function test_contradiction_severity_low_does_not_block(): void
    {
        $this->assertFalse(ContradictionSeverity::Low->blocksPublishing());
    }

    public function test_contradiction_severity_medium_does_not_block(): void
    {
        $this->assertFalse(ContradictionSeverity::Medium->blocksPublishing());
    }

    public function test_contradiction_severity_high_blocks(): void
    {
        $this->assertTrue(ContradictionSeverity::High->blocksPublishing());
    }

    public function test_contradiction_severity_critical_blocks(): void
    {
        $this->assertTrue(ContradictionSeverity::Critical->blocksPublishing());
    }

    public function test_research_session_round_trips_through_row(): void
    {
        $session = new ResearchSession(
            id: null,
            correlationId: 'corr-123',
            topic: 'Test topic',
            vertical: 'news',
            status: SessionStatus::Gathering,
            topicCluster: null,
            confidenceScore: null,
            createdAt: EntityDates::now(),
            updatedAt: EntityDates::now(),
            completedAt: null,
        );

        $restored = ResearchSession::fromRow(array_merge($session->toRow(), ['id' => 1]));

        $this->assertSame('corr-123', $restored->correlationId);
        $this->assertSame('Test topic', $restored->topic);
        $this->assertSame(SessionStatus::Gathering, $restored->status);
        $this->assertNull($restored->confidenceScore);
    }

    public function test_research_session_with_status_transitions_updates_timestamp_and_completed_at(): void
    {
        $session = new ResearchSession(1, 'corr', 'Topic', 'news', SessionStatus::Gathering, null, null, EntityDates::now(), EntityDates::now(), null);

        $completed = $session->withStatus(SessionStatus::Completed);

        $this->assertSame(SessionStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completedAt);
    }

    public function test_research_session_non_completing_transition_leaves_completed_at_null(): void
    {
        $session = new ResearchSession(1, 'corr', 'Topic', 'news', SessionStatus::Gathering, null, null, EntityDates::now(), EntityDates::now(), null);

        $analyzing = $session->withStatus(SessionStatus::Analyzing);

        $this->assertNull($analyzing->completedAt);
    }

    public function test_claim_with_status_preserves_other_fields(): void
    {
        $claim = new Claim(1, 1, 'Statement text', 0.5, ClaimStatus::Unverified, EntityDates::now());

        $updated = $claim->withStatus(ClaimStatus::Supported);

        $this->assertSame(ClaimStatus::Supported, $updated->status);
        $this->assertSame('Statement text', $updated->statement);
        $this->assertSame(0.5, $updated->confidenceScore);
    }
}

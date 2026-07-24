<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\DTO\ExtractedClaimData;
use AINewsAutomator\Research\DTO\ExtractedEntityData;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\Contradiction;
use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Events\ClaimExtractedEvent;
use AINewsAutomator\Research\Events\ContradictionDetectedEvent;
use AINewsAutomator\Research\Events\ResearchSessionCompletedEvent;
use AINewsAutomator\Research\Events\ResearchSessionStartedEvent;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\Research\Fakes\FakeClaimExtractor;
use AINewsAutomator\Tests\Research\Fakes\FakeContradictionDetector;
use AINewsAutomator\Tests\Research\Fakes\FakeEntityExtractor;
use AINewsAutomator\Tests\Research\Fakes\FakeTopicClusterer;
use AINewsAutomator\Tests\Research\Fakes\ResearchSessionManagerTestFactory;
use PHPUnit\Framework\TestCase;

final class ResearchSessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
    }

    public function test_start_session_creates_gathering_session_and_dispatches_event(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();

        $fired = false;
        $harness->events->addListener(ResearchSessionStartedEvent::class, function () use (&$fired): void {
            $fired = true;
        });

        $sessionId = $harness->manager->startSession('Test Topic');

        $session = $harness->sessions->find($sessionId);
        $this->assertNotNull($session);
        $this->assertSame(SessionStatus::Gathering, $session->status);
        $this->assertTrue($fired);
    }

    public function test_add_evidence_to_gathering_session_succeeds(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Test Topic');

        $evidenceId = $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', 0.8, 'snippet text', null);

        $this->assertNotNull($harness->evidence->find($evidenceId));
    }

    public function test_add_evidence_to_completed_session_throws(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Test Topic');
        $harness->manager->analyzeSession($sessionId); // no evidence -> completes immediately

        $this->expectException(SessionStateException::class);
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'text', null);
    }

    public function test_analyze_session_with_no_evidence_completes_gracefully(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Test Topic');

        $summary = $harness->manager->analyzeSession($sessionId);

        $this->assertSame(SessionStatus::Completed, $harness->sessions->find($sessionId)->status);
        $this->assertSame([], $summary->claims);
    }

    public function test_analyzing_twice_throws(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Test Topic');
        $harness->manager->analyzeSession($sessionId);

        $this->expectException(SessionStateException::class);
        $harness->manager->analyzeSession($sessionId);
    }

    public function test_extracted_claims_are_persisted_and_linked_to_evidence(): void
    {
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('The sky is blue.', 0.9));

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor);
        $sessionId = $harness->manager->startSession('Sky color');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'The sky is blue today.', null);

        $summary = $harness->manager->analyzeSession($sessionId);

        $this->assertCount(1, $summary->claims);
        $this->assertSame('The sky is blue.', $summary->claims[0]->claim->statement);
        $this->assertCount(1, $summary->claims[0]->citations);
    }

    public function test_extracted_entities_are_recorded(): void
    {
        $entityExtractor = new FakeEntityExtractor();
        $entityExtractor->willReturn(new ExtractedEntityData('Acme Corp', 'organization'));

        $harness = ResearchSessionManagerTestFactory::build(entityExtractor: $entityExtractor);
        $sessionId = $harness->manager->startSession('Acme news');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'Acme Corp announced...', null);

        $summary = $harness->manager->analyzeSession($sessionId);

        $this->assertCount(1, $summary->entities);
        $this->assertSame('Acme Corp', $summary->entities[0]->name);
    }

    public function test_detected_contradiction_marks_claim_contradicted_and_blocks_publishing(): void
    {
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('Revenue was $5 million.', 0.8));

        $contradictionDetector = new FakeContradictionDetector();
        $contradictionDetector->willReturn(new Contradiction(
            null, 1, 1, 2, 'Conflicting revenue figures.', ContradictionSeverity::Critical, false, EntityDates::now()
        ));

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor, contradictionDetector: $contradictionDetector);
        $sessionId = $harness->manager->startSession('Revenue');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'Revenue text', null);

        $summary = $harness->manager->analyzeSession($sessionId);

        $this->assertSame(ClaimStatus::Contradicted, $summary->claims[0]->claim->status);
        $this->assertTrue($summary->hasBlockingContradictions(), 'Critical severity must block publishing.');
    }

    public function test_low_severity_contradiction_does_not_block_publishing(): void
    {
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('A minor figure.', 0.8));

        $contradictionDetector = new FakeContradictionDetector();
        $contradictionDetector->willReturn(new Contradiction(
            null, 1, 1, 2, 'Minor rounding variance.', ContradictionSeverity::Low, false, EntityDates::now()
        ));

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor, contradictionDetector: $contradictionDetector);
        $sessionId = $harness->manager->startSession('Minor');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'text', null);

        $summary = $harness->manager->analyzeSession($sessionId);

        $this->assertFalse($summary->hasBlockingContradictions(), 'Low severity must not block publishing.');
    }

    public function test_completed_event_carries_correct_claim_count(): void
    {
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('Claim one.', 0.7));

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor);

        $capturedCount = null;
        $harness->events->addListener(ResearchSessionCompletedEvent::class, function (ResearchSessionCompletedEvent $e) use (&$capturedCount): void {
            $capturedCount = $e->claimCount;
        });

        $sessionId = $harness->manager->startSession('Topic');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'text', null);
        $harness->manager->analyzeSession($sessionId);

        $this->assertSame(1, $capturedCount);
    }

    public function test_abandon_session_sets_abandoned_status(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Topic');

        $harness->manager->abandonSession($sessionId);

        $this->assertSame(SessionStatus::Abandoned, $harness->sessions->find($sessionId)->status);
    }

    public function test_summarize_throws_for_incomplete_session(): void
    {
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Topic');

        $this->expectException(SessionStateException::class);
        $harness->sessions->summarize($sessionId);
    }

    // --- Regression tests added during release-candidate remediation ---

    public function test_abandoning_a_completed_session_throws(): void
    {
        // Regression test for Issue 1 (release-candidate audit): a
        // terminal Completed session must not be silently overwritable.
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Topic');
        $harness->manager->analyzeSession($sessionId); // -> Completed

        $this->expectException(SessionStateException::class);
        $harness->manager->abandonSession($sessionId);
    }

    public function test_abandoning_an_already_abandoned_session_throws(): void
    {
        // Regression test for Issue 1: abandoning is not idempotent —
        // a second abandon attempt on an already-terminal session throws.
        $harness = ResearchSessionManagerTestFactory::build();
        $sessionId = $harness->manager->startSession('Topic');
        $harness->manager->abandonSession($sessionId);

        $this->expectException(SessionStateException::class);
        $harness->manager->abandonSession($sessionId);
    }

    public function test_claim_extracted_event_fires_before_contradiction_detected_event_for_the_same_claim(): void
    {
        // Regression test for Issue 2 (release-candidate audit): a
        // listener must never see a ContradictionDetectedEvent
        // referencing a claim it hasn't been told about yet.
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('Disputed figure.', 0.7));

        $contradictionDetector = new FakeContradictionDetector();
        $contradictionDetector->willReturn(new Contradiction(
            null, 1, 1, 2, 'Conflicting figures.', ContradictionSeverity::High, false, EntityDates::now()
        ));

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor, contradictionDetector: $contradictionDetector);

        $order = [];
        $harness->events->addListener(ClaimExtractedEvent::class, function () use (&$order): void {
            $order[] = 'claim_extracted';
        });
        $harness->events->addListener(ContradictionDetectedEvent::class, function () use (&$order): void {
            $order[] = 'contradiction_detected';
        });

        $sessionId = $harness->manager->startSession('Disputed');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'text', null);
        $harness->manager->analyzeSession($sessionId);

        $this->assertSame(['claim_extracted', 'contradiction_detected'], $order);
    }

    public function test_claims_from_a_second_evidence_item_are_checked_against_claims_from_the_first(): void
    {
        // Regression test for Issue 6 (release-candidate audit): the
        // $existingClaims accumulator must carry claims across evidence
        // items within one analyzeSession() pass, not just within one
        // evidence item's own claim loop.
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('First claim.', 0.7));
        $claimExtractor->willReturn(new ExtractedClaimData('Second claim.', 0.7));

        $contradictionDetector = new FakeContradictionDetector();

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor, contradictionDetector: $contradictionDetector);
        $sessionId = $harness->manager->startSession('Cross-evidence');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'first text', null);
        $harness->manager->addEvidence($sessionId, 'https://x.test/b', 'rss', 'x.test', null, 'second text', null);

        $harness->manager->analyzeSession($sessionId);

        // The detector is called once per extracted claim (2 claims total,
        // one per evidence item since FakeClaimExtractor's queue yields
        // one claim per extract() call).
        $this->assertSame(2, $contradictionDetector->callCount);
    }

    public function test_second_claims_existing_claims_argument_includes_the_first_claim(): void
    {
        // More precise version of the above: asserts WHAT the detector
        // received, not just how many times it was called.
        $claimExtractor = new FakeClaimExtractor();
        $claimExtractor->willReturn(new ExtractedClaimData('First claim.', 0.7));
        $claimExtractor->willReturn(new ExtractedClaimData('Second claim.', 0.7));

        $contradictionDetector = new class implements \AINewsAutomator\Research\Contracts\ContradictionDetectorInterface {
            /** @var list<array{new: string, existingCount: int}> */
            public array $capturedCalls = [];

            public function detectFor(\AINewsAutomator\Research\Entities\Claim $newClaim, array $existingClaims): array
            {
                $this->capturedCalls[] = ['new' => $newClaim->statement, 'existingCount' => count($existingClaims)];
                return [];
            }
        };

        $harness = ResearchSessionManagerTestFactory::build(claimExtractor: $claimExtractor, contradictionDetector: $contradictionDetector);
        $sessionId = $harness->manager->startSession('Cross-evidence');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'first text', null);
        $harness->manager->addEvidence($sessionId, 'https://x.test/b', 'rss', 'x.test', null, 'second text', null);

        $harness->manager->analyzeSession($sessionId);

        $this->assertCount(2, $contradictionDetector->capturedCalls);
        $this->assertSame(0, $contradictionDetector->capturedCalls[0]['existingCount'], 'First claim has no prior claims to compare against.');
        $this->assertSame(1, $contradictionDetector->capturedCalls[1]['existingCount'], 'Second claim must be compared against the first.');
    }

    public function test_topic_cluster_is_persisted_when_clusterer_returns_a_label(): void
    {
        // Regression test for Issue 7 (release-candidate audit): the
        // withTopicCluster() code path was previously unreachable in tests.
        $entityExtractor = new FakeEntityExtractor();
        $entityExtractor->willReturn(new ExtractedEntityData('Acme Corp', 'organization'));

        $harness = ResearchSessionManagerTestFactory::build(
            entityExtractor: $entityExtractor,
            topicClusterer: new FakeTopicClusterer('acme-corp'),
        );

        $sessionId = $harness->manager->startSession('Acme news');
        $harness->manager->addEvidence($sessionId, 'https://x.test/a', 'rss', 'x.test', null, 'Acme Corp text', null);
        $harness->manager->analyzeSession($sessionId);

        $this->assertSame('acme-corp', $harness->sessions->find($sessionId)->topicCluster);
    }

    public function test_topic_cluster_remains_null_when_clusterer_returns_null(): void
    {
        $harness = ResearchSessionManagerTestFactory::build(topicClusterer: new FakeTopicClusterer(null));
        $sessionId = $harness->manager->startSession('Topic');

        $harness->manager->analyzeSession($sessionId);

        $this->assertNull($harness->sessions->find($sessionId)->topicCluster);
    }
}

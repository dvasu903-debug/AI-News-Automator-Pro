<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Research\Session\ResearchSessionManager;
use AINewsAutomator\Tests\AI\Fakes\FakeMetricsRepository;

final class ResearchSessionManagerTestHarness
{
    public function __construct(
        public readonly ResearchSessionManager $manager,
        public readonly FakeSessionRepository $sessions,
        public readonly FakeEvidenceRepository $evidence,
        public readonly FakeClaimRepository $claims,
        public readonly FakeCitationRepository $citations,
        public readonly FakeExtractedEntityRepository $entities,
        public readonly FakeContradictionRepository $contradictions,
        public readonly EventDispatcher $events,
        public readonly FakeMetricsRepository $metrics,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Research\Contracts\ClaimExtractorInterface;
use AINewsAutomator\Research\Contracts\ContradictionDetectorInterface;
use AINewsAutomator\Research\Contracts\EntityExtractorInterface;
use AINewsAutomator\Research\Contracts\TopicClustererInterface;
use AINewsAutomator\Research\Session\ResearchSessionManager;
use AINewsAutomator\Research\Session\ResearchSummaryBuilder;
use AINewsAutomator\Research\Diversity\SourceDiversityAnalyzer;
use AINewsAutomator\Research\Timeline\TimelineBuilder;
use AINewsAutomator\Tests\AI\Fakes\FakeMetricsRepository;

/**
 * Builds a fully-wired ResearchSessionManager from fakes, for
 * orchestration tests — mirrors AI's AIManagerTestFactory pattern
 * (tests/AI/Fakes) exactly. Override parameters are typed against the
 * INTERFACE, not the concrete Fake* class, so a test can pass either the
 * standard queue-based fake or a custom spy/stub implementing the same
 * interface (see ResearchSessionManagerTest's cross-evidence tests).
 */
final class ResearchSessionManagerTestFactory
{
    public static function build(
        ?ClaimExtractorInterface $claimExtractor = null,
        ?EntityExtractorInterface $entityExtractor = null,
        ?ContradictionDetectorInterface $contradictionDetector = null,
        ?TopicClustererInterface $topicClusterer = null,
    ): ResearchSessionManagerTestHarness {
        $evidence = new FakeEvidenceRepository();
        $claims = new FakeClaimRepository();
        $citations = new FakeCitationRepository();
        $entities = new FakeExtractedEntityRepository();
        $contradictions = new FakeContradictionRepository();

        $summaryBuilder = new ResearchSummaryBuilder(
            $claims,
            $citations,
            $entities,
            $contradictions,
            $evidence,
            new SourceDiversityAnalyzer(),
            new TimelineBuilder($evidence),
        );

        $sessions = new FakeSessionRepository($summaryBuilder);

        $correlation = new CorrelationContext('test-correlation');
        $events = new EventDispatcher();
        $metadataFactory = new EventMetadataFactory($correlation);
        $metrics = new FakeMetricsRepository();
        $logger = new OptionBackedLogger($correlation, Environment::Development);

        $manager = new ResearchSessionManager(
            $sessions,
            $evidence,
            $claims,
            $citations,
            $entities,
            $contradictions,
            $claimExtractor ?? new FakeClaimExtractor(),
            $entityExtractor ?? new FakeEntityExtractor(),
            $contradictionDetector ?? new FakeContradictionDetector(),
            new FakeConfidenceScorer(),
            $topicClusterer ?? new FakeTopicClusterer(),
            $events,
            $metadataFactory,
            $metrics,
            $logger,
            $correlation,
        );

        return new ResearchSessionManagerTestHarness($manager, $sessions, $evidence, $claims, $citations, $entities, $contradictions, $events, $metrics);
    }
}

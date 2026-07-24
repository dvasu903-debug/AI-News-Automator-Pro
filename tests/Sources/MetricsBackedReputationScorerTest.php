<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources;

use AINewsAutomator\Sources\Reputation\MetricsBackedReputationScorer;
use AINewsAutomator\Tests\AI\Fakes\FakeMetricsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Reuses AI's FakeMetricsRepository (tests/AI/Fakes) rather than defining
 * a second identical fake — the same cross-module test reuse already
 * used between AI's PromptTemplateTest and Storage's FakeWpdb.
 */
final class MetricsBackedReputationScorerTest extends TestCase
{
    public function test_no_history_returns_neutral_score(): void
    {
        $scorer = new MetricsBackedReputationScorer(new FakeMetricsRepository());
        $this->assertSame(0.5, $scorer->scoreFor(1));
    }

    public function test_all_successes_scores_one(): void
    {
        $metrics = new FakeMetricsRepository();
        $metrics->increment('source.fetch_success', 5, ['source_id' => 1]);

        $scorer = new MetricsBackedReputationScorer($metrics);
        $this->assertSame(1.0, $scorer->scoreFor(1));
    }

    public function test_all_failures_scores_zero(): void
    {
        $metrics = new FakeMetricsRepository();
        $metrics->increment('source.fetch_failure', 3, ['source_id' => 1]);

        $scorer = new MetricsBackedReputationScorer($metrics);
        $this->assertSame(0.0, $scorer->scoreFor(1));
    }

    public function test_mixed_history_computes_ratio(): void
    {
        $metrics = new FakeMetricsRepository();
        $metrics->increment('source.fetch_success', 3, ['source_id' => 1]);
        $metrics->increment('source.fetch_failure', 1, ['source_id' => 1]);

        $scorer = new MetricsBackedReputationScorer($metrics);
        $this->assertSame(0.75, $scorer->scoreFor(1));
    }
}

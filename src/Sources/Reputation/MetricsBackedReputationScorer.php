<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Reputation;

use AINewsAutomator\Sources\Contracts\SourceReputationInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;

/**
 * A computed view over Storage's MetricsRepositoryInterface — no new
 * table, no second source of truth (mirrors how AI's cost tracking reads
 * purely from ModelCatalogInterface rather than storing its own copy).
 * Reputation is a rolling success ratio derived from fetch-outcome
 * counters already being recorded by the job handlers.
 */
final class MetricsBackedReputationScorer implements SourceReputationInterface
{
    public function __construct(private readonly MetricsRepositoryInterface $metrics)
    {
    }

    public function scoreFor(int $sourceId): float
    {
        $dimensions = ['source_id' => $sourceId];

        $success = $this->metrics->counterValue('source.fetch_success', $dimensions);
        $failure = $this->metrics->counterValue('source.fetch_failure', $dimensions);

        $total = $success + $failure;

        if ($total === 0) {
            // No history yet — neither penalize nor reward; a neutral
            // midpoint is more honest than claiming a perfect or zero score.
            return 0.5;
        }

        return $success / $total;
    }
}

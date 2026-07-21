<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

/**
 * A computed view over Storage's MetricsRepositoryInterface — no new
 * table, no second source of truth. Reputation is derived from fetch
 * success/failure telemetry already being recorded.
 */
interface SourceReputationInterface
{
    /**
     * @return float Between 0.0 (always fails) and 1.0 (always succeeds).
     */
    public function scoreFor(int $sourceId): float;
}

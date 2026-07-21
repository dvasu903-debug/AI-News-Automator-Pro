<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Generic metrics storage: atomic counters (`ana_metric_counters`) for
 * "current total" style metrics, and discrete events (`ana_metrics`) for
 * time-series aggregation (cost trends, usage over time). Security's
 * SecurityMetricsInterface is rebound to an adapter over this interface
 * (see module README, rebinding scope).
 */
interface MetricsRepositoryInterface
{
    /**
     * Atomically increments a running counter. Race-free: uses
     * INSERT ... ON DUPLICATE KEY UPDATE, not read-modify-write.
     *
     * @param array<string, mixed> $dimensions
     */
    public function increment(string $metricKey, int $by = 1, array $dimensions = []): void;

    /**
     * @param array<string, mixed> $dimensions
     */
    public function counterValue(string $metricKey, array $dimensions = []): int;

    /**
     * @return array<string, int> Every counter for this key, keyed by dimension hash — mainly for dashboards listing all variants.
     */
    public function allCounters(): array;

    /**
     * Records a discrete metric event for later time-series aggregation.
     *
     * @param array<string, mixed> $dimensions
     */
    public function record(string $metricKey, int $value, array $dimensions = []): void;

    /**
     * @return array<string, int> bucket (Y-m-d H:00:00) => summed value
     */
    public function aggregateHourly(string $metricKey, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}

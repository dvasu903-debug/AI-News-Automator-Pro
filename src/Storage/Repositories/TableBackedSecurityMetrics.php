<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;

/**
 * Implements Security's existing SecurityMetricsInterface (Module 2) by
 * delegating to the generic MetricsRepositoryInterface — the same
 * rebinding pattern as AuditRepository. StorageServiceProvider rebinds
 * Security\Contracts\SecurityMetricsInterface to this class; no Security
 * file changes, since every Security class depends on the interface, not
 * the old option-backed SecurityMetrics concrete.
 *
 * This is what fixes the specific race condition identified in the
 * Module 3 audit (W2): the old SecurityMetrics did read-modify-write on a
 * single wp_options row; MetricsRepository's increment() is an atomic
 * `INSERT ... ON DUPLICATE KEY UPDATE`.
 */
final class TableBackedSecurityMetrics implements SecurityMetricsInterface
{
    private const NAMESPACE = 'security';

    public function __construct(private readonly MetricsRepositoryInterface $metrics)
    {
    }

    public function increment(string $metric, int $by = 1): void
    {
        $this->metrics->increment($this->namespaced($metric), $by);
    }

    public function get(string $metric): int
    {
        return $this->metrics->counterValue($this->namespaced($metric));
    }

    public function all(): array
    {
        $all = $this->metrics->allCounters();
        $result = [];

        $prefix = self::NAMESPACE . '.';
        foreach ($all as $key => $value) {
            // Key shape from MetricsRepository::allCounters() is
            // "metric_key:dimension_hash" — strip our namespace prefix and
            // the (empty, since we pass no dimensions) hash suffix.
            $metricKey = explode(':', $key, 2)[0];
            if (str_starts_with($metricKey, $prefix)) {
                $result[substr($metricKey, strlen($prefix))] = $value;
            }
        }

        return $result;
    }

    private function namespaced(string $metric): string
    {
        return self::NAMESPACE . '.' . $metric;
    }
}

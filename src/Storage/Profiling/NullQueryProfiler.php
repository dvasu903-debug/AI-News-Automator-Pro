<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Profiling;

use AINewsAutomator\Storage\Contracts\QueryProfilerInterface;

/**
 * Default QueryProfilerInterface binding: does nothing. Costs a single
 * empty method call per query. A future Monitoring module binds a real
 * implementation (recording timing/counts, e.g. into ana_metrics) without
 * any Storage class needing to change — Connection depends on the
 * interface, never this concrete class.
 */
final class NullQueryProfiler implements QueryProfilerInterface
{
    public function recordQuery(string $sql, array $params, float $durationMs): void
    {
        // Intentionally empty.
    }
}

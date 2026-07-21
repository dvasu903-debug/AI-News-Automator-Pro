<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Extension point for query profiling/observability. The default binding
 * (NullQueryProfiler) does nothing and costs nothing; a future Monitoring
 * module can bind a real implementation that records timing/counts per
 * query without any Storage class changing — Connection calls this
 * interface around every executed query regardless of which
 * implementation is bound.
 */
interface QueryProfilerInterface
{
    /**
     * @param list<mixed> $params
     */
    public function recordQuery(string $sql, array $params, float $durationMs): void;
}

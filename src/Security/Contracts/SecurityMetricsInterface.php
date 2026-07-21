<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * Counters for security-relevant operations. Read by the Analytics module
 * later; interface-first so the backing store can change.
 */
interface SecurityMetricsInterface
{
    public function increment(string $metric, int $by = 1): void;

    public function get(string $metric): int;

    /**
     * @return array<string, int> All tracked metrics.
     */
    public function all(): array;
}

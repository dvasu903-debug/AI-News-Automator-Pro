<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Implemented by repositories whose table is subject to age-based
 * retention pruning (Logs, Audit, Job History, Metric events — NOT
 * Metric counters, which are running totals that should never be
 * deleted). RetentionPolicy implementations depend on this interface
 * rather than a concrete repository, so a retention policy for a given
 * table works uniformly regardless of which repository backs it.
 */
interface PurgeableInterface
{
    /**
     * Deletes rows older than $days, batched (never one unbounded
     * DELETE). Returns the number of rows removed.
     */
    public function purgeOlderThan(int $days): int;
}

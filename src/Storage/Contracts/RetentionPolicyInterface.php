<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * A configurable data-retention rule for one table/repository.
 */
interface RetentionPolicyInterface
{
    /** Logical table this policy applies to (see Database\Tables). */
    public function appliesTo(): string;

    public function retentionDays(): int;

    /**
     * Deletes rows older than retentionDays(), batched (never one
     * unbounded DELETE). Returns the number of rows removed.
     */
    public function purge(ConnectionInterface $connection): int;
}

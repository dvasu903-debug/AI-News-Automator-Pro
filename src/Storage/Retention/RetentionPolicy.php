<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Retention;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Contracts\RetentionPolicyInterface;

/**
 * A generic retention policy: applies to one logical table, reads its
 * retention window, and purges via the injected PurgeableInterface
 * repository. One instance per table, constructed with the specific
 * repository and day count — see RetentionServiceProvider wiring for how
 * the four default policies (logs, audit, job history, metric events) are
 * assembled from Core's ConfigRepositoryInterface values.
 */
final class RetentionPolicy implements RetentionPolicyInterface
{
    public function __construct(
        private readonly string $logicalTable,
        private readonly PurgeableInterface $repository,
        private readonly int $days,
    ) {
    }

    public function appliesTo(): string
    {
        return $this->logicalTable;
    }

    public function retentionDays(): int
    {
        return $this->days;
    }

    public function purge(ConnectionInterface $connection): int
    {
        return $this->repository->purgeOlderThan($this->days);
    }
}

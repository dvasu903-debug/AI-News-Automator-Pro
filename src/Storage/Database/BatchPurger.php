<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Database;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * Shared batched-delete logic for age-based retention purging. Used by
 * every PurgeableInterface implementation (Logs, Audit, Job History,
 * Metric events) so the "never one unbounded DELETE" batching behavior
 * lives in exactly one place rather than being copy-pasted per repository.
 */
final class BatchPurger
{
    public static function purgeOlderThan(
        ConnectionInterface $connection,
        string $logicalTable,
        string $dateColumn,
        int $days,
        int $batchSize = 1000
    ): int {
        $cutoff = EntityDates::now()->modify("-{$days} days");
        $table = $connection->table($logicalTable);
        $totalDeleted = 0;

        do {
            $deleted = $connection->statement(
                "DELETE FROM `{$table}` WHERE `{$dateColumn}` < %s LIMIT %d",
                [EntityDates::toMysql($cutoff), $batchSize]
            );
            $totalDeleted += $deleted;
        } while ($deleted === $batchSize);

        return $totalDeleted;
    }
}

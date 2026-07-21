<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Retention;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\RetentionPolicyInterface;

/**
 * Runs every registered retention policy. Exists now as a plain callable
 * service; wiring it to an actual recurring WP-Cron schedule is deferred
 * to Module 7 (Scheduler) — this class and its run() method are what
 * Module 7 will schedule, the same "extension point now, full wiring
 * later" posture used for Export/Import/Backup.
 */
final class RetentionCleanupJob
{
    /**
     * @param list<RetentionPolicyInterface> $policies
     */
    public function __construct(
        private readonly array $policies,
        private readonly ConnectionInterface $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int> logical table => rows purged
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->policies as $policy) {
            $purged = $policy->purge($this->connection);
            $results[$policy->appliesTo()] = $purged;

            if ($purged > 0) {
                $this->logger->info('Retention purge: removed {count} rows from {table} (older than {days} days).', [
                    'count' => $purged,
                    'table' => $policy->appliesTo(),
                    'days'  => $policy->retentionDays(),
                ]);
            }
        }

        return $results;
    }
}

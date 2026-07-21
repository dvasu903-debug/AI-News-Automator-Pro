<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\JobHistoryRepositoryInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Database\BatchPurger;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\JobHistoryEntry;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;

/**
 * @extends AbstractRepository<JobHistoryEntry>
 */
final class JobHistoryRepository extends AbstractRepository implements JobHistoryRepositoryInterface, PurgeableInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::JOBS;
    }

    protected function hydrate(array $row): JobHistoryEntry
    {
        return JobHistoryEntry::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var JobHistoryEntry $entity */
        return $entity->toRow();
    }

    public function find(int $jobId): ?JobHistoryEntry
    {
        return $this->findRow($jobId);
    }

    public function recordFromQueue(JobHistoryEntry $entry): void
    {
        // Reuses the originating queue row's id as the history row's own
        // primary key (stable job identity across its lifecycle) — an
        // explicit id-carrying insert, not the auto-increment path.
        $row = $entry->toRow();
        $row['id'] = $entry->id;

        $this->connection->insert($this->table(), $row);
    }

    public function paginate(array $filters, int $page = 1, int $perPage = 25): PageResult
    {
        return $this->paginateRows($filters, [], $page, $perPage);
    }

    public function statsFor(string $jobType, \DateTimeImmutable $since): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->whereAll([
                Filter::equals('job_type', $jobType),
                Filter::greaterThanOrEqual('finished_at', $since->format('Y-m-d H:i:s')),
            ])
            ->select(['status'])
            ->get();

        $stats = ['completed' => 0, 'failed' => 0, 'cancelled' => 0];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }

    public function purgeOlderThan(int $days): int
    {
        return BatchPurger::purgeOlderThan($this->connection, $this->table(), 'finished_at', $days);
    }
}

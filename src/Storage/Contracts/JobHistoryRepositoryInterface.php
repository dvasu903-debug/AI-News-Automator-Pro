<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\JobHistoryEntry;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;

/**
 * Read/query access to the historical `ana_jobs` ledger. Writes to this
 * table only ever happen via QueueRepositoryInterface's completion
 * methods (the queue-to-history move), never directly — keeping the
 * queue/history transition atomic and in one place.
 */
interface JobHistoryRepositoryInterface
{
    public function find(int $jobId): ?JobHistoryEntry;

    /**
     * Writes a history entry for a job that just moved out of the active
     * queue. Called only by QueueRepositoryInterface as part of its
     * atomic completion move — never called directly by other modules,
     * which is why it takes the pre-built entry rather than raw fields.
     */
    public function recordFromQueue(JobHistoryEntry $entry): void;

    /**
     * @param list<Filter> $filters
     * @return PageResult<JobHistoryEntry>
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 25): PageResult;

    /**
     * @return array{completed: int, failed: int, cancelled: int}
     */
    public function statsFor(string $jobType, \DateTimeImmutable $since): array;
}

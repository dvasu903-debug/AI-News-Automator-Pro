<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Fakes;

use AINewsAutomator\Storage\Contracts\JobHistoryRepositoryInterface;
use AINewsAutomator\Storage\Entities\JobHistoryEntry;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;

/**
 * A minimal in-memory JobHistoryRepositoryInterface test double for
 * QueueCompletionListenerTest — verifies the listener correctly looks
 * up a completed/failed job's result/error via this repository rather
 * than inventing a second source of truth for it.
 */
final class FakeJobHistoryRepository implements JobHistoryRepositoryInterface
{
    /** @var array<int, JobHistoryEntry> */
    private array $entries = [];

    public function seed(int $jobId, JobStatus $status, ?array $result = null, ?string $error = null): void
    {
        $this->entries[$jobId] = new JobHistoryEntry(
            id: $jobId,
            jobType: 'test.job',
            status: $status,
            priority: 100,
            attempts: 1,
            worker: 'test-worker',
            payload: [],
            result: $result,
            error: $error,
            correlationId: null,
            createdAt: EntityDates::now(),
            startedAt: EntityDates::now(),
            finishedAt: EntityDates::now(),
        );
    }

    public function find(int $jobId): ?JobHistoryEntry
    {
        return $this->entries[$jobId] ?? null;
    }

    public function recordFromQueue(JobHistoryEntry $entry): void
    {
        $this->entries[(int) $entry->id] = $entry;
    }

    public function paginate(array $filters, int $page = 1, int $perPage = 25): PageResult
    {
        return new PageResult([], $page, $perPage, 0, 0, false);
    }

    public function statsFor(string $jobType, \DateTimeImmutable $since): array
    {
        return ['completed' => 0, 'failed' => 0, 'cancelled' => 0];
    }
}

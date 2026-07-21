<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\QueueJob;

/**
 * Operates on the active `ana_queue` table. On completion/failure, a job
 * moves out of this table into the JobHistoryRepositoryInterface's table
 * (ana_jobs) — see module README, "queue/history split".
 */
interface QueueRepositoryInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $jobType,
        array $payload,
        int $priority = 100,
        ?\DateTimeImmutable $runAfter = null,
        ?string $correlationId = null
    ): int;

    /**
     * Atomically claims up to $limit due jobs for the given worker,
     * marking them "processing". Returns the claimed jobs.
     *
     * @return list<QueueJob>
     */
    public function claimNextForWorker(string $worker, int $limit = 1): array;

    /**
     * @param array<string, mixed>|null $result
     */
    public function markSuccess(int $jobId, ?array $result = null): void;

    public function markFailure(int $jobId, string $error): void;

    /** Returns a job to "pending" without counting it as a new attempt-triggering enqueue. */
    public function release(int $jobId): void;

    public function find(int $jobId): ?QueueJob;

    /**
     * @return list<QueueJob>
     */
    public function bulkEnqueue(array $jobs): array;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Fakes;

use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Entities\QueueJob;

/**
 * A minimal in-memory QueueRepositoryInterface test double — used by
 * WorkflowSchedulerTest to verify Workflow's OWN logic (job-type
 * filtering, the foreign-job release safety net, success/failure
 * handling) without re-testing Storage's already-frozen, already-tested
 * QueueRepository/claimNextForWorker() implementation itself.
 */
final class FakeQueueRepository implements QueueRepositoryInterface
{
    /** @var array<int, QueueJob> */
    private array $jobs = [];
    private int $nextId = 1;

    /** @var list<int> Ids released back to pending, in call order. */
    public array $released = [];

    /** @var list<int> Ids marked successful, in call order. */
    public array $succeeded = [];

    /** @var list<array{id: int, error: string}> */
    public array $failed = [];

    public function enqueue(string $jobType, array $payload, int $priority = 100, ?\DateTimeImmutable $runAfter = null, ?string $correlationId = null): int
    {
        $id = $this->nextId++;

        $this->jobs[$id] = new QueueJob(
            id: $id,
            jobType: $jobType,
            status: JobStatus::Pending,
            priority: $priority,
            attempts: 0,
            maxAttempts: 5,
            worker: null,
            payload: $payload,
            correlationId: $correlationId,
            runAfter: $runAfter,
            lockedAt: null,
            createdAt: EntityDates::now(),
            startedAt: null,
        );

        return $id;
    }

    public function bulkEnqueue(array $jobs): array
    {
        return [];
    }

    public function claimNextForWorker(string $worker, int $limit = 1): array
    {
        $claimed = [];

        foreach ($this->jobs as $job) {
            if ($job->status !== JobStatus::Pending) {
                continue;
            }

            $claimed[] = $job;

            if (count($claimed) >= $limit) {
                break;
            }
        }

        return $claimed;
    }

    public function markSuccess(int $jobId, ?array $result = null): void
    {
        unset($this->jobs[$jobId]);
        $this->succeeded[] = $jobId;
    }

    public function markFailure(int $jobId, string $error): void
    {
        unset($this->jobs[$jobId]);
        $this->failed[] = ['id' => $jobId, 'error' => $error];
    }

    public function release(int $jobId): void
    {
        $this->released[] = $jobId;
        // Leave it "pending" so a real scheduler's next tick could claim
        // it again — mirrors Storage's real release() semantics.
    }

    public function find(int $jobId): ?QueueJob
    {
        return $this->jobs[$jobId] ?? null;
    }

    /**
     * Simulates a job of a foreign type already sitting in the shared
     * queue (e.g. enqueued by Sources) — the scenario the release-back
     * safety net exists for.
     */
    public function seedForeignJob(int $id, string $jobType): void
    {
        $this->jobs[$id] = new QueueJob(
            id: $id,
            jobType: $jobType,
            status: JobStatus::Pending,
            priority: 100,
            attempts: 0,
            maxAttempts: 5,
            worker: null,
            payload: [],
            correlationId: null,
            runAfter: null,
            lockedAt: null,
            createdAt: EntityDates::now(),
            startedAt: null,
        );
        $this->nextId = max($this->nextId, $id + 1);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\JobHistoryRepositoryInterface;
use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\JobHistoryEntry;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Entities\QueueJob;
use AINewsAutomator\Storage\Events\JobCompletedEvent;
use AINewsAutomator\Storage\Events\JobEnqueuedEvent;
use AINewsAutomator\Storage\Events\JobFailedEvent;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;

/**
 * @extends AbstractRepository<QueueJob>
 */
final class QueueRepository extends AbstractRepository implements QueueRepositoryInterface
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly TransactionManagerInterface $transactions,
        private readonly JobHistoryRepositoryInterface $jobHistory,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::QUEUE;
    }

    protected function hydrate(array $row): QueueJob
    {
        return QueueJob::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var QueueJob $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var QueueJob $entity */
        $errors = [];

        if (trim($entity->jobType) === '') {
            $errors['job_type'] = 'Job type is required.';
        }

        if ($entity->priority < 0 || $entity->priority > 32767) {
            $errors['priority'] = 'Priority must be between 0 and 32767.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Queue job failed validation.');
        }
    }

    public function enqueue(
        string $jobType,
        array $payload,
        int $priority = 100,
        ?\DateTimeImmutable $runAfter = null,
        ?string $correlationId = null
    ): int {
        $job = new QueueJob(
            id: null,
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

        $id = $this->insertRow($job);

        $this->events->dispatch(new JobEnqueuedEvent(
            $this->metadataFactory->create('Storage', ['job_type' => $jobType]),
            jobId: $id,
            jobType: $jobType,
            priority: $priority,
        ));

        return $id;
    }

    public function bulkEnqueue(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        /** @var list<QueueJob> $entities */
        $entities = array_map(function (array $spec): QueueJob {
            return new QueueJob(
                id: null,
                jobType: (string) $spec['job_type'],
                status: JobStatus::Pending,
                priority: (int) ($spec['priority'] ?? 100),
                attempts: 0,
                maxAttempts: 5,
                worker: null,
                payload: (array) ($spec['payload'] ?? []),
                correlationId: isset($spec['correlation_id']) ? (string) $spec['correlation_id'] : null,
                runAfter: $spec['run_after'] ?? null,
                lockedAt: null,
                createdAt: EntityDates::now(),
                startedAt: null,
            );
        }, $jobs);

        $this->insertRows($entities);

        // insertMany doesn't return individual ids portably across engines
        // for a multi-row insert; re-query the just-inserted batch by
        // correlation/created_at window would be fragile, so bulkEnqueue's
        // contract only guarantees the rows were written — callers needing
        // ids for further orchestration should use enqueue() individually,
        // or a future Queue module can extend this with a returning-ids
        // variant once a concrete batching use case defines what it needs.
        return $entities;
    }

    /**
     * How long a claimed (status=processing) job's lock is honored before
     * any other worker's claim call may reclaim it as abandoned.
     * Filterable via 'ai_news_automator_queue_stale_lock_timeout'.
     */
    private const STALE_LOCK_TIMEOUT_SECONDS = 900;

    public function claimNextForWorker(string $worker, int $limit = 1): array
    {
        /** @var list<array{0: QueueJob, 1: string}> $exhausted */
        $exhausted = [];

        $claimed = $this->transactions->transactional(function () use ($worker, $limit, &$exhausted): array {
            $now = EntityDates::now();

            // This one method bypasses the fluent QueryBuilder deliberately:
            // atomic claim needs FOR UPDATE row locking within a transaction,
            // which is outside the builder's narrow scope (see module
            // README). It remains fully parameterized via Connection's
            // prepared-statement methods — no value is ever concatenated.
            $table = $this->connection->table(Tables::QUEUE);

            // --- Stale-claim recovery sweep (authorized post-freeze fix —
            // see docs/verification/authorized-frozen-changes.txt). A worker
            // that dies mid-job leaves its row status=processing forever,
            // and nothing else in the system ever revisits it (empirically
            // confirmed during Module 7 runtime validation, Item 14: an
            // orphaned processing job was invisible to every subsequent
            // claim call). Recovery lives here, in the claim path itself,
            // so it needs no extra cron or sweep process: any healthy
            // worker's next claim heals the queue. The crashed execution
            // counts as a failed attempt (attempts + 1), so a job that
            // repeatedly kills its workers still exhausts max_attempts and
            // lands in job history as failed — the same terminal path
            // markFailure() uses — rather than crash-looping forever.
            $timeout = (int) apply_filters(
                'ai_news_automator_queue_stale_lock_timeout',
                self::STALE_LOCK_TIMEOUT_SECONDS
            );
            $cutoff = EntityDates::toMysql($now->modify(sprintf('-%d seconds', $timeout)));

            $stale = $this->connection->select(
                "SELECT * FROM `{$table}` WHERE status = %s AND locked_at <= %s LIMIT %d FOR UPDATE",
                [JobStatus::Processing->value, $cutoff, 50]
            );

            foreach ($stale as $staleRow) {
                $staleJob = $this->hydrate($staleRow);
                $nextAttempts = $staleJob->attempts + 1;

                if ($nextAttempts < $staleJob->maxAttempts) {
                    $this->connection->update(Tables::QUEUE, [
                        'status'    => JobStatus::Pending->value,
                        'attempts'  => $nextAttempts,
                        'worker'    => null,
                        'locked_at' => null,
                        'error'     => sprintf(
                            'Reclaimed from worker "%s": lock exceeded stale timeout (%d seconds).',
                            (string) $staleJob->worker,
                            $timeout
                        ),
                    ], ['id' => (int) $staleJob->id]);
                    continue;
                }

                $error = sprintf(
                    'Abandoned by worker "%s" and out of attempts (%d of %d): removed by stale-lock reclaim.',
                    (string) $staleJob->worker,
                    $nextAttempts,
                    $staleJob->maxAttempts
                );

                $this->deleteRow((int) $staleJob->id);
                $this->jobHistory->recordFromQueue(
                    JobHistoryEntry::fromQueueJob($staleJob, JobStatus::Failed, null, $error)
                );
                $exhausted[] = [$staleJob, $error];
            }

            // --- Normal pending claim, unchanged. Jobs re-pended by the
            // sweep above are immediately eligible here, in this same call.
            $candidates = $this->connection->select(
                "SELECT id FROM `{$table}` WHERE status = %s AND (run_after IS NULL OR run_after <= %s) ORDER BY priority DESC, created_at ASC LIMIT %d FOR UPDATE",
                [JobStatus::Pending->value, EntityDates::toMysql($now), $limit]
            );

            if ($candidates === []) {
                return [];
            }

            $ids = array_map(static fn (array $row): int => (int) $row['id'], $candidates);

            foreach ($ids as $id) {
                $this->connection->update(Tables::QUEUE, [
                    'status'     => JobStatus::Processing->value,
                    'worker'     => $worker,
                    'locked_at'  => EntityDates::toMysql($now),
                    'started_at' => EntityDates::toMysql($now),
                ], ['id' => $id]);
            }

            $claimed = $this->connection->newQuery(Tables::QUEUE)
                ->where(Filter::in('id', $ids))
                ->get();

            return array_map(fn (array $row) => $this->hydrate($row), $claimed);
        });

        // Events dispatch after the transaction commits, matching
        // markSuccess()/markFailure()'s established pattern.
        foreach ($exhausted as [$staleJob, $error]) {
            $this->events->dispatch(new JobFailedEvent(
                $this->metadataFactory->create('Storage', ['job_id' => $staleJob->id]),
                jobId: (int) $staleJob->id,
                jobType: $staleJob->jobType,
                error: $error,
                willRetry: false,
            ));
        }

        return $claimed;
    }

    public function markSuccess(int $jobId, ?array $result = null): void
    {
        $this->transactions->transactional(function () use ($jobId, $result): void {
            $job = $this->findRow($jobId);

            if ($job === null) {
                return;
            }

            $this->deleteRow($jobId);

            $history = JobHistoryEntry::fromQueueJob($job, JobStatus::Completed, $result, null);
            $this->jobHistory->recordFromQueue($history);
        });

        $this->events->dispatch(new JobCompletedEvent(
            $this->metadataFactory->create('Storage', ['job_id' => $jobId]),
            jobId: $jobId,
            jobType: '', // job row no longer exists post-move; type is in the history entry the listener can look up if needed
        ));
    }

    public function markFailure(int $jobId, string $error): void
    {
        $job = $this->findRow($jobId);

        if ($job === null) {
            return;
        }

        $willRetry = ($job->attempts + 1) < $job->maxAttempts;

        $this->transactions->transactional(function () use ($job, $error, $willRetry): void {
            if ($willRetry) {
                $this->connection->update(Tables::QUEUE, [
                    'status'    => JobStatus::Pending->value,
                    'attempts'  => $job->attempts + 1,
                    'worker'    => null,
                    'locked_at' => null,
                    'error'     => $error,
                ], ['id' => $job->id]);
                return;
            }

            $this->deleteRow((int) $job->id);

            $history = JobHistoryEntry::fromQueueJob($job, JobStatus::Failed, null, $error);
            $this->jobHistory->recordFromQueue($history);
        });

        $this->events->dispatch(new JobFailedEvent(
            $this->metadataFactory->create('Storage', ['job_id' => $jobId]),
            jobId: $jobId,
            jobType: $job->jobType,
            error: $error,
            willRetry: $willRetry,
        ));
    }

    public function release(int $jobId): void
    {
        $this->updateRow(['status' => JobStatus::Pending->value, 'worker' => null, 'locked_at' => null], ['id' => $jobId]);
    }

    public function find(int $jobId): ?QueueJob
    {
        return $this->findRow($jobId);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Scheduling;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowDefinitionRepositoryInterface;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;

/**
 * A single, independent WP-Cron hook scoped ONLY to the
 * `workflow.scheduled_run` job type — NOT a migration target for
 * Sources' SourceSyncScheduler in this pass (approved Decision 2).
 * Mirrors Sources\Scheduling\SourceSyncScheduler's exact two-phase
 * shape: enqueue due work, then claim-and-process only this module's
 * own job type from the SAME shared `ana_queue` table.
 *
 * Same safety note as Sources' scheduler: Storage's
 * QueueRepositoryInterface::claimNextForWorker() claims the next
 * pending job(s) by priority/run_after ONLY — it cannot filter by job
 * type (Storage is frozen, verified directly against QueueRepository's
 * implementation during the Audit phase). Any claimed job whose type
 * isn't `workflow.scheduled_run` is immediately released back to
 * pending via QueueRepositoryInterface::release() — never marked
 * failed, never processed — exactly the defensive pattern Sources'
 * scheduler needed, replicated here rather than reinvented.
 */
final class WorkflowScheduler
{
    private const HOOK = 'ana_workflow_scheduler_tick';
    private const CRON_INTERVAL_KEY = 'ana_workflow_five_minutes';
    private const CRON_INTERVAL_SECONDS = 300;
    private const WORKER_ID = 'workflow-scheduler';
    private const CLAIM_BATCH_SIZE = 5;
    private const JOB_TYPE = 'workflow.scheduled_run';

    public function __construct(
        private readonly WorkflowDefinitionRepositoryInterface $definitions,
        private readonly QueueRepositoryInterface $queue,
        private readonly WorkflowRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function hookName(): string
    {
        return self::HOOK;
    }

    public static function jobType(): string
    {
        return self::JOB_TYPE;
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function registerCronInterval(array $schedules): array
    {
        $schedules[self::CRON_INTERVAL_KEY] = [
            'interval' => self::CRON_INTERVAL_SECONDS,
            'display'  => 'Every 5 Minutes (AI News Automator — Workflow)',
        ];

        return $schedules;
    }

    public function schedule(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, self::CRON_INTERVAL_KEY, self::HOOK);
        }
    }

    public function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if ($timestamp !== false) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function tick(): void
    {
        $this->enqueueDueWorkflows();
        $this->processQueuedBatch();
    }

    private function enqueueDueWorkflows(): void
    {
        foreach ($this->definitions->allKeys() as $workflowKey) {
            $latest = $this->definitions->latest($workflowKey);

            if ($latest === null) {
                continue;
            }

            $trigger = is_array($latest->definition['trigger'] ?? null) ? $latest->definition['trigger'] : [];

            if (($trigger['type'] ?? null) !== 'scheduled') {
                continue;
            }

            if (!$this->isDue($workflowKey, $trigger)) {
                continue;
            }

            $this->queue->enqueue(self::JOB_TYPE, ['workflow_key' => $workflowKey]);
            $this->markEnqueued($workflowKey);
        }
    }

    private function processQueuedBatch(): void
    {
        $jobs = $this->queue->claimNextForWorker(self::WORKER_ID, self::CLAIM_BATCH_SIZE);

        foreach ($jobs as $job) {
            $jobId = (int) $job->id;

            if ($job->jobType !== self::JOB_TYPE) {
                // Not ours — release it back rather than mishandling it.
                // See class docblock: claimNextForWorker() doesn't filter
                // by job type.
                $this->logger->debug('Workflow scheduler released foreign job {id} of type "{type}" back to pending.', [
                    'id'   => $jobId,
                    'type' => $job->jobType,
                ]);
                $this->queue->release($jobId);
                continue;
            }

            $this->handleOwnedJob($jobId, $job->payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleOwnedJob(int $jobId, array $payload): void
    {
        $workflowKey = (string) ($payload['workflow_key'] ?? '');

        try {
            if ($workflowKey === '') {
                throw new \RuntimeException('Missing workflow_key in scheduled_run job payload.');
            }

            $run = $this->runner->run($workflowKey, 'scheduled');
            $this->queue->markSuccess($jobId, ['run_id' => $run->id]);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled workflow job {id} ({key}) failed: {error}', [
                'id'    => $jobId,
                'key'   => $workflowKey,
                'error' => $e->getMessage(),
            ]);
            $this->queue->markFailure($jobId, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $trigger
     */
    private function isDue(string $workflowKey, array $trigger): bool
    {
        $intervalSeconds = (int) ($trigger['config']['interval_seconds'] ?? self::CRON_INTERVAL_SECONDS);
        $lastEnqueued = get_transient($this->transientKey($workflowKey));

        return $lastEnqueued === false || (time() - (int) $lastEnqueued) >= $intervalSeconds;
    }

    private function markEnqueued(string $workflowKey): void
    {
        set_transient($this->transientKey($workflowKey), time(), DAY_IN_SECONDS);
    }

    private function transientKey(string $workflowKey): string
    {
        return 'ana_workflow_last_enqueued_' . md5($workflowKey);
    }
}

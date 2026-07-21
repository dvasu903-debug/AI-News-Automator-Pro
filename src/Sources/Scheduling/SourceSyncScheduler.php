<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Scheduling;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Sources\Jobs\CrawlUrlJobHandler;
use AINewsAutomator\Sources\Jobs\FetchSourceJobHandler;
use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * A single WP-Cron hook, scoped ONLY to `source.fetch`/`source.crawl` job
 * types — not a generic scheduler (approved Decision 3, ADR-0016). A
 * future dedicated Scheduler module is expected to eventually replace
 * this cron hook; until then, Module 5 owns just enough scheduling to
 * function.
 *
 * Important safety note: Storage's `QueueRepositoryInterface::claimNextForWorker()`
 * claims the next pending job(s) by priority/run_after ONLY — it does not
 * and cannot filter by job type (verified directly against
 * `QueueRepository`'s implementation; Storage is frozen, this cannot be
 * changed there). That means once a future module enqueues its own job
 * types into the SAME shared queue, this scheduler's claim call could
 * grab one of THEIR jobs. `processQueuedBatch()` handles this explicitly:
 * any claimed job whose type isn't one of ours is immediately released
 * back to pending via `QueueRepositoryInterface::release()` — never
 * marked failed, never processed — so it's picked up by whatever worker
 * actually owns it, with only a brief claim-and-release delay. This is
 * what makes "scoped only to source.fetch/source.crawl" a real behavioral
 * guarantee rather than just a docblock claim.
 */
final class SourceSyncScheduler
{
    private const HOOK = 'ana_sources_sync_tick';
    private const CRON_INTERVAL_KEY = 'ana_sources_five_minutes';
    private const CRON_INTERVAL_SECONDS = 300;
    private const WORKER_ID = 'sources-scheduler';
    private const CLAIM_BATCH_SIZE = 5;

    private const OWNED_JOB_TYPES = ['source.fetch', 'source.crawl'];

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly QueueRepositoryInterface $queue,
        private readonly FetchSourceJobHandler $fetchHandler,
        private readonly CrawlUrlJobHandler $crawlHandler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function hookName(): string
    {
        return self::HOOK;
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function registerCronInterval(array $schedules): array
    {
        $schedules[self::CRON_INTERVAL_KEY] = [
            'interval' => self::CRON_INTERVAL_SECONDS,
            'display'  => 'Every 5 Minutes (AI News Automator — Sources)',
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

    /**
     * The cron-hooked entry point: enqueue due sources, then claim and
     * process a small batch of this module's own job types.
     */
    public function tick(): void
    {
        $this->enqueueDueSources();
        $this->processQueuedBatch();
    }

    private function enqueueDueSources(): void
    {
        foreach ($this->sources->dueForFetch() as $source) {
            if ($source->type === 'web_crawler') {
                $this->enqueueCrawlJobs($source);
                continue;
            }

            $this->queue->enqueue('source.fetch', ['source_id' => $source->id]);
        }
    }

    private function enqueueCrawlJobs(SourceRecord $source): void
    {
        foreach ($this->seedUrlsFor($source) as $seedUrl) {
            $this->queue->enqueue('source.crawl', [
                'source_id' => $source->id,
                'seed_url'  => $seedUrl,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function seedUrlsFor(SourceRecord $source): array
    {
        $configured = $source->config['seed_urls'] ?? null;

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        $single = $source->config['seed_url'] ?? $source->config['url'] ?? null;

        return $single !== null ? [(string) $single] : [];
    }

    private function processQueuedBatch(): void
    {
        $jobs = $this->queue->claimNextForWorker(self::WORKER_ID, self::CLAIM_BATCH_SIZE);

        foreach ($jobs as $job) {
            $jobId = (int) $job->id;

            if (!in_array($job->jobType, self::OWNED_JOB_TYPES, true)) {
                // Not ours — release it back rather than mishandling it.
                // See class docblock: claimNextForWorker() doesn't filter
                // by job type, so this is expected once another module's
                // job types share the queue.
                $this->logger->debug('Sources scheduler released foreign job {id} of type "{type}" back to pending.', [
                    'id'   => $jobId,
                    'type' => $job->jobType,
                ]);
                $this->queue->release($jobId);
                continue;
            }

            $this->handleOwnedJob($jobId, $job->jobType, $job->payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleOwnedJob(int $jobId, string $jobType, array $payload): void
    {
        try {
            $result = match ($jobType) {
                'source.fetch' => $this->fetchHandler->handle($payload),
                'source.crawl' => $this->crawlHandler->handle($payload),
                default => throw new \LogicException('Unreachable — job type already validated as owned.'),
            };

            $this->queue->markSuccess($jobId, $result);
        } catch (\Throwable $e) {
            $this->logger->error('Sources job {id} ({type}) failed: {error}', [
                'id'    => $jobId,
                'type'  => $jobType,
                'error' => $e->getMessage(),
            ]);
            $this->queue->markFailure($jobId, $e->getMessage());
        }
    }
}

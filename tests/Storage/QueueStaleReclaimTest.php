<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Events\JobFailedEvent;
use AINewsAutomator\Storage\Repositories\QueueRepository;
use AINewsAutomator\Tests\Workflow\Fakes\FakeJobHistoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers the stale-claim recovery added to
 * QueueRepository::claimNextForWorker() — the authorized post-freeze
 * Storage fix from Module 7 runtime validation Item 14 (see
 * docs/verification/authorized-frozen-changes.txt). Before that fix, a
 * worker dying mid-job left its row status=processing forever, invisible
 * to every future claim call — empirically confirmed on the live
 * validation environment.
 *
 * "Time passing" is simulated by backdating the row's locked_at directly
 * through FakeWpdb's own public update() API — the same mechanism a real
 * wall clock produces, without the test needing to sleep for the
 * 15-minute stale timeout.
 */
final class QueueStaleReclaimTest extends TestCase
{
    private const BACKDATED = '2020-01-01 00:00:00';

    private FakeWpdb $wpdb;
    private QueueRepository $queue;
    private FakeJobHistoryRepository $history;
    private EventDispatcher $events;

    /** @var list<JobFailedEvent> */
    private array $failedEvents = [];

    protected function setUp(): void
    {
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->createTable('wp_ana_queue');

        $this->history = new FakeJobHistoryRepository();
        $this->events = new EventDispatcher();
        $this->failedEvents = [];
        $this->events->addListener(JobFailedEvent::class, function (JobFailedEvent $e): void {
            $this->failedEvents[] = $e;
        });

        $this->queue = new QueueRepository(
            new Connection(),
            new PassthroughTransactions(),
            $this->history,
            $this->events,
            new EventMetadataFactory(new CorrelationContext('stale-reclaim-test')),
        );
    }

    public function test_normal_pending_claim_still_works(): void
    {
        $id = $this->queue->enqueue('test.job', ['k' => 'v']);

        $claimed = $this->queue->claimNextForWorker('worker-a');

        $this->assertCount(1, $claimed);
        $this->assertSame($id, $claimed[0]->id);
        $this->assertSame(JobStatus::Processing, $claimed[0]->status);
        $this->assertSame('worker-a', $claimed[0]->worker);
        $this->assertSame(0, $claimed[0]->attempts);
    }

    public function test_stale_processing_job_is_reclaimed_and_claimable_again(): void
    {
        $id = $this->queue->enqueue('test.job', []);
        $this->queue->claimNextForWorker('crashed-worker');
        $this->backdateLock($id);

        $claimed = $this->queue->claimNextForWorker('healthy-worker');

        $this->assertCount(1, $claimed, 'The reclaimed job must be claimable in the same call.');
        $this->assertSame($id, $claimed[0]->id);
        $this->assertSame('healthy-worker', $claimed[0]->worker);
        $this->assertSame(1, $claimed[0]->attempts, 'The crashed execution must count as a failed attempt.');

        // And the recovered job can complete normally.
        $this->queue->markSuccess($id, ['recovered' => true]);
        $this->assertNull($this->queue->find($id));
    }

    public function test_non_stale_processing_job_is_not_reclaimed(): void
    {
        $id = $this->queue->enqueue('test.job', []);
        $this->queue->claimNextForWorker('busy-worker');
        // locked_at stays "now" — well inside the stale timeout.

        $claimed = $this->queue->claimNextForWorker('other-worker');

        $this->assertSame([], $claimed, 'A live lock must be honored — no reclaim.');
        $stillOwned = $this->queue->find($id);
        $this->assertNotNull($stillOwned);
        $this->assertSame('busy-worker', $stillOwned->worker);
        $this->assertSame(JobStatus::Processing, $stillOwned->status);
    }

    public function test_repeated_stale_reclaims_respect_max_attempts(): void
    {
        $id = $this->queue->enqueue('test.job', []);
        $this->queue->claimNextForWorker('worker-0');

        // Default maxAttempts is 5. Each backdate+claim cycle simulates one
        // worker crash: reclaim increments attempts, then the same call
        // claims it again. After 4 cycles attempts=4; the 5th reclaim
        // attempt (nextAttempts=5, not < 5) must remove the job instead.
        for ($cycle = 1; $cycle <= 4; $cycle++) {
            $this->backdateLock($id);
            $claimed = $this->queue->claimNextForWorker("worker-{$cycle}");
            $this->assertCount(1, $claimed, "Cycle {$cycle} should still reclaim.");
            $this->assertSame($cycle, $claimed[0]->attempts);
        }

        $this->backdateLock($id);
        $claimed = $this->queue->claimNextForWorker('worker-final');

        $this->assertSame([], $claimed, 'An out-of-attempts stale job must not be reclaimed.');
        $this->assertNull($this->queue->find($id), 'The exhausted job must be removed from the queue.');

        $historyEntry = $this->history->find($id);
        $this->assertNotNull($historyEntry, 'The exhausted job must be recorded in job history.');
        $this->assertSame(JobStatus::Failed, $historyEntry->status);

        $this->assertCount(1, $this->failedEvents, 'A terminal JobFailedEvent must be dispatched.');
        $this->assertSame($id, $this->failedEvents[0]->jobId);
        $this->assertFalse($this->failedEvents[0]->willRetry);
    }

    private function backdateLock(int $jobId): void
    {
        $this->wpdb->update('wp_ana_queue', ['locked_at' => self::BACKDATED], ['id' => $jobId]);
    }
}

/**
 * Minimal TransactionManagerInterface double: runs the work directly.
 * Transaction semantics themselves are Connection/DB behavior outside
 * this test's scope (same convention as the other Storage tests).
 */
final class PassthroughTransactions implements TransactionManagerInterface
{
    public function begin(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollback(): void
    {
    }

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function inTransaction(): bool
    {
        return false;
    }
}

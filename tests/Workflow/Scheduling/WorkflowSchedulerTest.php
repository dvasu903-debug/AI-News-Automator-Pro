<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Scheduling;

use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use AINewsAutomator\Tests\Workflow\Fakes\FakeLogger;
use AINewsAutomator\Tests\Workflow\Fakes\FakeQueueRepository;
use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;
use AINewsAutomator\Workflow\Repositories\ApprovalRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowDefinitionRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowRunRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowStepResultRepository;
use AINewsAutomator\Workflow\Runner\ConditionEvaluator;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;
use AINewsAutomator\Workflow\Registry\ActionRegistry;
use AINewsAutomator\Workflow\Retry\WorkflowStepRetryExecutor;
use AINewsAutomator\Workflow\Scheduling\WorkflowScheduler;
use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the approved Decision 2 safety net: claimNextForWorker()
 * doesn't filter by job type (verified against the real Storage
 * QueueRepository during the Audit phase), so WorkflowScheduler must
 * release any foreign job type it claims back to pending rather than
 * process or fail it — exactly the pattern
 * Sources\Scheduling\SourceSyncScheduler needed.
 */
final class WorkflowSchedulerTest extends TestCase
{
    private FakeQueueRepository $queue;
    private WorkflowDefinitionRepository $definitions;
    private WorkflowScheduler $scheduler;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        foreach (['workflow_definitions', 'workflow_runs', 'workflow_step_results', 'workflow_approvals'] as $table) {
            $wpdb->createTable('wp_ana_' . $table);
        }

        $connection = new Connection();
        $this->definitions = new WorkflowDefinitionRepository($connection);
        $this->queue = new FakeQueueRepository();

        $correlation = new CorrelationContext('test');
        $logger = new FakeLogger();

        $runner = new WorkflowRunner(
            $this->definitions,
            new WorkflowRunRepository($connection),
            new WorkflowStepResultRepository($connection),
            new ApprovalRepository($connection),
            new ActionRegistry(),
            new ConditionEvaluator(),
            new WorkflowStepRetryExecutor($logger, maxAttempts: 1, baseDelayMs: 0, maxDelayMs: 0),
            new EventDispatcher(),
            new EventMetadataFactory($correlation),
            $correlation,
            $logger,
        );

        $this->scheduler = new WorkflowScheduler($this->definitions, $this->queue, $runner, $logger);
    }

    public function test_foreign_job_type_is_released_back_not_processed(): void
    {
        $this->queue->seedForeignJob(100, 'source.fetch');

        $this->scheduler->tick();

        $this->assertSame([100], $this->queue->released);
        $this->assertSame([], $this->queue->succeeded);
        $this->assertSame([], $this->queue->failed);
    }

    public function test_scheduled_workflow_is_enqueued_and_then_processed_on_the_same_tick(): void
    {
        $this->definitions->saveNewVersion(new WorkflowDefinitionVersion(
            id: null,
            workflowKey: 'daily-digest',
            version: 1,
            definition: ['trigger' => ['type' => 'scheduled', 'config' => ['interval_seconds' => 300]], 'steps' => []],
            createdAt: EntityDates::now(),
        ));

        $this->scheduler->tick();

        $this->assertCount(1, $this->queue->succeeded);
    }

    public function test_non_scheduled_workflow_is_never_enqueued(): void
    {
        $this->definitions->saveNewVersion(new WorkflowDefinitionVersion(
            id: null,
            workflowKey: 'manual-only',
            version: 1,
            definition: ['trigger' => ['type' => 'manual'], 'steps' => []],
            createdAt: EntityDates::now(),
        ));

        $this->scheduler->tick();

        $this->assertSame([], $this->queue->succeeded);
        $this->assertSame([], $this->queue->failed);
    }

    public function test_a_missing_workflow_key_payload_marks_the_job_failed_not_thrown(): void
    {
        $this->queue->enqueue(WorkflowScheduler::jobType(), []); // no workflow_key

        $this->scheduler->tick();

        $this->assertCount(1, $this->queue->failed);
    }
}

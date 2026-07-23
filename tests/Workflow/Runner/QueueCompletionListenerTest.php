<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow\Runner;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Events\JobCompletedEvent;
use AINewsAutomator\Storage\Events\JobFailedEvent;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use AINewsAutomator\Tests\Workflow\Fakes\FakeJobHistoryRepository;
use AINewsAutomator\Tests\Workflow\Fakes\FakeLogger;
use AINewsAutomator\Tests\Workflow\Fakes\StubAction;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\Entities\StepStatus;
use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;
use AINewsAutomator\Workflow\Entities\WorkflowRunStatus;
use AINewsAutomator\Workflow\Registry\ActionRegistry;
use AINewsAutomator\Workflow\Repositories\ApprovalRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowDefinitionRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowRunRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowStepResultRepository;
use AINewsAutomator\Workflow\Runner\ConditionEvaluator;
use AINewsAutomator\Workflow\Runner\QueueCompletionListener;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;
use AINewsAutomator\Workflow\Retry\WorkflowStepRetryExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Decision 3 bridge: Storage's existing
 * JobCompletedEvent/JobFailedEvent reused, not a second async
 * framework — including the "willRetry = true means don't resume yet"
 * rule, which is the one behavior that's easy to get wrong here (Storage
 * itself will retry the job; resuming early would desynchronize the
 * step's Deferred status from the job's actual outcome).
 */
final class QueueCompletionListenerTest extends TestCase
{
    private WorkflowStepResultRepository $stepResults;
    private WorkflowRunRepository $runs;
    private FakeJobHistoryRepository $jobHistory;
    private QueueCompletionListener $listener;
    private ActionRegistry $actions;
    private WorkflowDefinitionRepository $definitions;
    private WorkflowRunner $runner;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        foreach (['workflow_definitions', 'workflow_runs', 'workflow_step_results', 'workflow_approvals'] as $table) {
            $wpdb->createTable('wp_ana_' . $table);
        }

        $connection = new Connection();
        $this->definitions = new WorkflowDefinitionRepository($connection);
        $this->runs = new WorkflowRunRepository($connection);
        $this->stepResults = new WorkflowStepResultRepository($connection);
        $this->actions = new ActionRegistry();
        $this->jobHistory = new FakeJobHistoryRepository();
        $logger = new FakeLogger();
        $correlation = new CorrelationContext('test');

        $this->runner = new WorkflowRunner(
            $this->definitions,
            $this->runs,
            $this->stepResults,
            new ApprovalRepository($connection),
            $this->actions,
            new ConditionEvaluator(),
            new WorkflowStepRetryExecutor($logger, maxAttempts: 1, baseDelayMs: 0, maxDelayMs: 0),
            new EventDispatcher(),
            new EventMetadataFactory($correlation),
            $correlation,
            $logger,
        );

        $this->listener = new QueueCompletionListener($this->runner, $this->jobHistory, $logger);
    }

    private function startDeferredRun(): int
    {
        $this->actions->register(new StubAction('deferred_action', [ActionResult::deferred(55)]));
        $this->definitions->saveNewVersion(new WorkflowDefinitionVersion(
            null, 'demo', 1, ['trigger' => ['type' => 'manual'], 'steps' => [['key' => 's1', 'action' => 'deferred_action']]], EntityDates::now()
        ));

        $run = $this->runner->run('demo', 'manual');

        return (int) $run->id;
    }

    public function test_job_completed_event_resumes_and_completes_the_run(): void
    {
        $runId = $this->startDeferredRun();
        $this->jobHistory->seed(55, JobStatus::Completed, ['payload' => 'value']);

        $this->listener->onJobCompleted(new JobCompletedEvent($this->metadata(), jobId: 55, jobType: ''));

        $run = $this->runs->find($runId);
        $this->assertSame(WorkflowRunStatus::Completed, $run->status);
    }

    public function test_job_failed_event_with_will_retry_true_does_not_resume(): void
    {
        $runId = $this->startDeferredRun();

        $this->listener->onJobFailed(new JobFailedEvent($this->metadata(), jobId: 55, jobType: 'test.job', error: 'transient', willRetry: true));

        // Still Deferred — Storage itself will retry the job; our step
        // must not be resumed as a failure prematurely.
        $step = $this->stepResults->forRun($runId)[0];
        $this->assertSame(StepStatus::Deferred, $step->status);

        $run = $this->runs->find($runId);
        $this->assertSame(WorkflowRunStatus::Running, $run->status);
    }

    public function test_job_failed_event_with_will_retry_false_resumes_as_failure(): void
    {
        $runId = $this->startDeferredRun();
        $this->jobHistory->seed(55, JobStatus::Failed, null, 'permanent failure');

        $this->listener->onJobFailed(new JobFailedEvent($this->metadata(), jobId: 55, jobType: 'test.job', error: 'permanent failure', willRetry: false));

        $run = $this->runs->find($runId);
        $this->assertSame(WorkflowRunStatus::Failed, $run->status);
    }

    private function metadata(): \AINewsAutomator\Core\Events\EventMetadata
    {
        return new \AINewsAutomator\Core\Events\EventMetadata('evt-1', time(), 'corr-1', 'Storage', []);
    }
}

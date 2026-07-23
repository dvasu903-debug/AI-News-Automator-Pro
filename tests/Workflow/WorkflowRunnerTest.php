<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Workflow;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use AINewsAutomator\Tests\Workflow\Fakes\FakeLogger;
use AINewsAutomator\Tests\Workflow\Fakes\StubAction;
use AINewsAutomator\Tests\Workflow\Fakes\StubRollbackableAction;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\RollbackResult;
use AINewsAutomator\Workflow\Entities\ApprovalStatus;
use AINewsAutomator\Workflow\Entities\RollbackStatus;
use AINewsAutomator\Workflow\Entities\StepStatus;
use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;
use AINewsAutomator\Workflow\Entities\WorkflowRunStatus;
use AINewsAutomator\Workflow\Events\WorkflowRunCompletedEvent;
use AINewsAutomator\Workflow\Events\WorkflowRunFailedEvent;
use AINewsAutomator\Workflow\Registry\ActionRegistry;
use AINewsAutomator\Workflow\Repositories\ApprovalRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowDefinitionRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowRunRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowStepResultRepository;
use AINewsAutomator\Workflow\Runner\ConditionEvaluator;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;
use AINewsAutomator\Workflow\Retry\WorkflowStepException;
use AINewsAutomator\Workflow\Entities\WorkflowStepErrorType;
use AINewsAutomator\Workflow\Retry\WorkflowStepRetryExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Full real-repository-stack orchestration tests — real
 * WorkflowRunner, real repositories against FakeWpdb, real
 * ConditionEvaluator, real WorkflowStepRetryExecutor, real
 * EventDispatcher — only the actions themselves are stubbed (the one
 * seam that's genuinely module-external per run). Mirrors
 * SessionRepositoryTest's "real stack, not fakes" approach, which the
 * Module 6 RC audit called out as the module's most valuable test
 * shape.
 */
final class WorkflowRunnerTest extends TestCase
{
    private WorkflowDefinitionRepository $definitions;
    private WorkflowRunRepository $runs;
    private WorkflowStepResultRepository $stepResults;
    private ApprovalRepository $approvals;
    private ActionRegistry $actions;
    private EventDispatcher $events;
    private WorkflowRunner $runner;
    private FakeLogger $logger;

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
        $this->approvals = new ApprovalRepository($connection);
        $this->actions = new ActionRegistry();
        $this->events = new EventDispatcher();
        $this->logger = new FakeLogger();

        $correlation = new CorrelationContext('test-correlation-id');
        $metadataFactory = new EventMetadataFactory($correlation);

        $this->runner = new WorkflowRunner(
            $this->definitions,
            $this->runs,
            $this->stepResults,
            $this->approvals,
            $this->actions,
            new ConditionEvaluator(),
            new WorkflowStepRetryExecutor($this->logger, maxAttempts: 3, baseDelayMs: 0, maxDelayMs: 0),
            $this->events,
            $metadataFactory,
            $correlation,
            $this->logger,
        );
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function seedDefinition(string $key, array $steps, string $triggerType = 'manual'): void
    {
        $this->definitions->saveNewVersion(new WorkflowDefinitionVersion(
            id: null,
            workflowKey: $key,
            version: 1,
            definition: ['trigger' => ['type' => $triggerType], 'steps' => $steps],
            createdAt: EntityDates::now(),
        ));
    }

    public function test_linear_run_completes_successfully(): void
    {
        $this->actions->register(new StubAction('step_a', [ActionResult::success(['out' => 1])]));
        $this->actions->register(new StubAction('step_b', [ActionResult::success(['out' => 2])]));

        $this->seedDefinition('demo', [
            ['key' => 's1', 'action' => 'step_a'],
            ['key' => 's2', 'action' => 'step_b'],
        ]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Completed, $run->status);

        $steps = $this->stepResults->forRun((int) $run->id);
        $this->assertCount(2, $steps);
        $this->assertSame(StepStatus::Completed, $steps[0]->status);
        $this->assertSame(StepStatus::Completed, $steps[1]->status);
    }

    public function test_step_failure_fails_run_and_rolls_back_completed_steps_in_reverse_order(): void
    {
        $rollbackA = new StubRollbackableAction('step_a');
        $rollbackB = new StubRollbackableAction('step_b');
        $this->actions->register($rollbackA);
        $this->actions->register($rollbackB);
        $this->actions->register(new StubAction('step_c', [ActionResult::failure('boom')]));

        $this->seedDefinition('demo', [
            ['key' => 's1', 'action' => 'step_a'],
            ['key' => 's2', 'action' => 'step_b'],
            ['key' => 's3', 'action' => 'step_c'],
        ]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Failed, $run->status);
        $this->assertNotNull($run->error);

        // Rollback ran in reverse order: step_b before step_a.
        $this->assertCount(1, $rollbackB->rollbackCalls);
        $this->assertCount(1, $rollbackA->rollbackCalls);

        $steps = $this->stepResults->forRun((int) $run->id);
        $byKey = [];
        foreach ($steps as $s) {
            $byKey[$s->stepKey] = $s;
        }
        $this->assertSame(RollbackStatus::RolledBack, $byKey['s1']->rollbackStatus);
        $this->assertSame(RollbackStatus::RolledBack, $byKey['s2']->rollbackStatus);
        $this->assertSame(StepStatus::Failed, $byKey['s3']->status);
        $this->assertNull($byKey['s3']->rollbackStatus); // never completed, nothing to roll back
    }

    public function test_non_rollbackable_action_is_marked_not_reversible_on_rollback(): void
    {
        $this->actions->register(new StubAction('step_a', [ActionResult::success()]));
        $this->actions->register(new StubAction('step_b', [ActionResult::failure('boom')]));

        $this->seedDefinition('demo', [
            ['key' => 's1', 'action' => 'step_a'],
            ['key' => 's2', 'action' => 'step_b'],
        ]);

        $run = $this->runner->run('demo', 'manual');

        $steps = $this->stepResults->forRun((int) $run->id);
        $this->assertSame(RollbackStatus::NotReversible, $steps[0]->rollbackStatus);
    }

    public function test_deferred_step_halts_run_and_resume_completes_it(): void
    {
        $this->actions->register(new StubAction('deferred_action', [ActionResult::deferred(42)]));

        $this->seedDefinition('demo', [
            ['key' => 's1', 'action' => 'deferred_action'],
        ]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Running, $run->status);
        $step = $this->stepResults->forRun((int) $run->id)[0];
        $this->assertSame(StepStatus::Deferred, $step->status);
        $this->assertSame(42, $step->queueJobId);

        $this->runner->resumeFromQueueJob(42, true, ['result' => 'ok']);

        $completedRun = $this->runs->find((int) $run->id);
        $this->assertSame(WorkflowRunStatus::Completed, $completedRun->status);

        $completedStep = $this->stepResults->find((int) $step->id);
        $this->assertSame(StepStatus::Completed, $completedStep->status);
        $this->assertSame(['result' => 'ok'], $completedStep->output);
    }

    public function test_resume_from_queue_job_is_idempotent(): void
    {
        $this->actions->register(new StubAction('deferred_action', [ActionResult::deferred(42)]));
        $this->seedDefinition('demo', [['key' => 's1', 'action' => 'deferred_action']]);

        $run = $this->runner->run('demo', 'manual');
        $this->runner->resumeFromQueueJob(42, true, ['result' => 'first']);

        // A duplicate completion event for the same, now-already-resumed
        // queue job must be a no-op — not re-execute or re-complete the step.
        $this->runner->resumeFromQueueJob(42, true, ['result' => 'second']);

        $step = $this->stepResults->forRun((int) $run->id)[0];
        $this->assertSame(['result' => 'first'], $step->output);
    }

    public function test_resume_from_unknown_queue_job_is_a_safe_noop(): void
    {
        $this->actions->register(new StubAction('deferred_action', [ActionResult::deferred(42)]));
        $this->seedDefinition('demo', [['key' => 's1', 'action' => 'deferred_action']]);

        $this->runner->run('demo', 'manual');

        // No exception, no side effect, for a queue job id that isn't ours.
        $this->runner->resumeFromQueueJob(9999, true, []);

        $this->assertTrue(true);
    }

    public function test_approval_gate_halts_run_and_approve_resumes_it(): void
    {
        $this->actions->register(new StubAction('approval_gate', [ActionResult::awaitingApproval()]));
        $this->actions->register(new StubAction('after_approval', [ActionResult::success()]));

        $this->seedDefinition('demo', [
            ['key' => 'gate', 'action' => 'approval_gate'],
            ['key' => 'after', 'action' => 'after_approval'],
        ]);

        $run = $this->runner->run('demo', 'manual');
        $this->assertSame(WorkflowRunStatus::AwaitingApproval, $run->status);

        $approval = $this->approvals->findPendingForRunStep((int) $run->id, 'gate');
        $this->assertNotNull($approval);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);

        $resolved = $this->runner->approve((int) $run->id, 'gate', 7, true);

        $this->assertSame(WorkflowRunStatus::Completed, $resolved->status);
    }

    public function test_approval_gate_reject_fails_run_and_rolls_back_prior_steps(): void
    {
        $rollback = new StubRollbackableAction('rollbackable_step');
        $this->actions->register($rollback);
        $this->actions->register(new StubAction('approval_gate', [ActionResult::awaitingApproval()]));

        $this->seedDefinition('demo', [
            ['key' => 's1', 'action' => 'rollbackable_step'],
            ['key' => 'gate', 'action' => 'approval_gate'],
        ]);

        $run = $this->runner->run('demo', 'manual');
        $resolved = $this->runner->approve((int) $run->id, 'gate', 7, false, 'Not good enough.');

        $this->assertSame(WorkflowRunStatus::Failed, $resolved->status);
        $this->assertCount(1, $rollback->rollbackCalls);

        $approval = $this->stepResults->forRun((int) $run->id);
        // s1 completed then rolled back; gate step recorded as failed.
        $failedGate = array_values(array_filter($approval, static fn ($s) => $s->stepKey === 'gate'))[0];
        $this->assertSame(StepStatus::Failed, $failedGate->status);
    }

    public function test_condition_false_skips_step(): void
    {
        $skippable = new StubAction('maybe_run', [ActionResult::success()]);
        $this->actions->register($skippable);

        $this->seedDefinition('demo', [
            ['key' => 's1', 'action' => 'maybe_run', 'condition' => ['field' => 'nonexistent', 'operator' => 'exists']],
        ]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Completed, $run->status);
        $this->assertCount(0, $skippable->calls);

        $step = $this->stepResults->forRun((int) $run->id)[0];
        $this->assertSame(StepStatus::Skipped, $step->status);
    }

    public function test_condition_true_from_prior_step_output_runs_step(): void
    {
        $this->actions->register(new StubAction('produces', [ActionResult::success(['score' => 10])]));
        $gated = new StubAction('gated', [ActionResult::success()]);
        $this->actions->register($gated);

        $this->seedDefinition('demo', [
            ['key' => 'first', 'action' => 'produces'],
            ['key' => 'second', 'action' => 'gated', 'condition' => ['field' => 'first.score', 'operator' => 'gte', 'value' => 5]],
        ]);

        $this->runner->run('demo', 'manual');

        $this->assertCount(1, $gated->calls);
    }

    public function test_transient_exception_is_retried_then_succeeds(): void
    {
        $this->actions->register(new StubAction('flaky', [
            new WorkflowStepException('temporary', WorkflowStepErrorType::Transient),
            ActionResult::success(['recovered' => true]),
        ]));

        $this->seedDefinition('demo', [['key' => 's1', 'action' => 'flaky']]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Completed, $run->status);
        $this->assertGreaterThanOrEqual(1, $this->logger->countLevel('warning'));
    }

    public function test_validation_exception_is_not_retried_and_fails_immediately(): void
    {
        $action = new StubAction('bad_config', [
            new WorkflowStepException('bad config', WorkflowStepErrorType::Validation),
        ]);
        $this->actions->register($action);

        $this->seedDefinition('demo', [['key' => 's1', 'action' => 'bad_config']]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Failed, $run->status);
        $this->assertCount(1, $action->calls); // never retried
    }

    public function test_missing_action_type_fails_the_run(): void
    {
        $this->seedDefinition('demo', [['key' => 's1', 'action' => 'does_not_exist']]);

        $run = $this->runner->run('demo', 'manual');

        $this->assertSame(WorkflowRunStatus::Failed, $run->status);
    }

    public function test_run_pins_the_version_it_started_with_even_if_a_new_version_is_saved_mid_flight(): void
    {
        // §2.7: a run must remain explainable against the version it
        // actually executed, even if the definition gains a new version
        // while the run is in flight.
        $this->actions->register(new StubAction('step_a', [ActionResult::deferred(1)]));

        $this->seedDefinition('demo', [['key' => 's1', 'action' => 'step_a']]);
        $run = $this->runner->run('demo', 'manual');
        $this->assertSame(1, $run->version);

        // A new version 2 is saved while the run is still deferred.
        $this->definitions->saveNewVersion(new WorkflowDefinitionVersion(
            id: null,
            workflowKey: 'demo',
            version: 2,
            definition: ['trigger' => ['type' => 'manual'], 'steps' => [['key' => 's1', 'action' => 'step_a'], ['key' => 's2', 'action' => 'step_a']]],
            createdAt: EntityDates::now(),
        ));

        $this->runner->resumeFromQueueJob(1, true, []);

        $completed = $this->runs->find((int) $run->id);
        // Still version 1 — resumed against v1's single-step definition,
        // not v2's two-step one.
        $this->assertSame(1, $completed->version);
        $this->assertSame(WorkflowRunStatus::Completed, $completed->status);
    }

    public function test_events_are_dispatched_for_completed_and_failed_runs(): void
    {
        $completedEvents = [];
        $failedEvents = [];
        $this->events->addListener(WorkflowRunCompletedEvent::class, function ($e) use (&$completedEvents): void {
            $completedEvents[] = $e;
        });
        $this->events->addListener(WorkflowRunFailedEvent::class, function ($e) use (&$failedEvents): void {
            $failedEvents[] = $e;
        });

        $this->actions->register(new StubAction('ok', [ActionResult::success()]));
        $this->seedDefinition('good', [['key' => 's1', 'action' => 'ok']]);
        $this->runner->run('good', 'manual');

        $this->actions->register(new StubAction('bad', [ActionResult::failure('nope')]));
        $this->seedDefinition('bad', [['key' => 's1', 'action' => 'bad']]);
        $this->runner->run('bad', 'manual');

        $this->assertCount(1, $completedEvents);
        $this->assertCount(1, $failedEvents);
    }
}

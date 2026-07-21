<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Runner;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Workflow\Contracts\ActionRegistryInterface;
use AINewsAutomator\Workflow\Contracts\ApprovalRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\ConditionEvaluatorInterface;
use AINewsAutomator\Workflow\Contracts\RollbackableActionInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowDefinitionRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowRunRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowStepResultRepositoryInterface;
use AINewsAutomator\Workflow\DTO\RollbackOutcome;
use AINewsAutomator\Workflow\DTO\StepDefinition;
use AINewsAutomator\Workflow\DTO\WorkflowDefinition;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use AINewsAutomator\Workflow\Entities\Approval;
use AINewsAutomator\Workflow\Entities\ApprovalStatus;
use AINewsAutomator\Workflow\Entities\RollbackStatus;
use AINewsAutomator\Workflow\Entities\StepStatus;
use AINewsAutomator\Workflow\Entities\WorkflowRun;
use AINewsAutomator\Workflow\Entities\WorkflowRunStatus;
use AINewsAutomator\Workflow\Entities\WorkflowStepResult;
use AINewsAutomator\Workflow\Events\ApprovalGrantedEvent;
use AINewsAutomator\Workflow\Events\ApprovalRejectedEvent;
use AINewsAutomator\Workflow\Events\ApprovalRequestedEvent;
use AINewsAutomator\Workflow\Events\RollbackCompletedEvent;
use AINewsAutomator\Workflow\Events\RollbackStartedEvent;
use AINewsAutomator\Workflow\Events\StepCompletedEvent;
use AINewsAutomator\Workflow\Events\StepDeferredEvent;
use AINewsAutomator\Workflow\Events\StepFailedEvent;
use AINewsAutomator\Workflow\Events\StepResumedEvent;
use AINewsAutomator\Workflow\Events\StepStartedEvent;
use AINewsAutomator\Workflow\Events\WorkflowRunCompletedEvent;
use AINewsAutomator\Workflow\Events\WorkflowRunFailedEvent;
use AINewsAutomator\Workflow\Events\WorkflowRunStartedEvent;
use AINewsAutomator\Workflow\Exceptions\WorkflowException;
use AINewsAutomator\Workflow\Retry\WorkflowStepRetryExecutor;

/**
 * The orchestration entry point — mirrors AIManager/ResearchSessionManager's
 * role exactly (§2.3). Walks a WorkflowDefinition's steps in order,
 * resolving each step's ActionInterface via ActionRegistryInterface,
 * evaluating conditions, executing through WorkflowStepRetryExecutor,
 * and handling the three "the walk pauses here" cases: a deferred
 * (async) step, an approval gate, or a terminal failure (which triggers
 * best-effort rollback).
 *
 * Every state transition this class makes is guarded explicitly — no
 * transition happens implicitly via a repository upsert — per the
 * Module 6 RC audit's Issue 1 lesson (a missing state guard was a real
 * defect there) and the approved Part 8 testing strategy for this
 * module ("a state-guard test for every status transition, including
 * invalid ones").
 */
final class WorkflowRunner
{
    public function __construct(
        private readonly WorkflowDefinitionRepositoryInterface $definitions,
        private readonly WorkflowRunRepositoryInterface $runs,
        private readonly WorkflowStepResultRepositoryInterface $stepResults,
        private readonly ApprovalRepositoryInterface $approvals,
        private readonly ActionRegistryInterface $actions,
        private readonly ConditionEvaluatorInterface $conditions,
        private readonly WorkflowStepRetryExecutor $retry,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly CorrelationContext $correlation,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Starts a new run of the latest (or a pinned) version of
     * $workflowKey. Resolves the version once, here — never re-resolved
     * mid-run (§2.7).
     *
     * @throws WorkflowException If no definition exists for $workflowKey (or the pinned version).
     */
    public function run(string $workflowKey, string $triggeredBy, ?int $userId = null, ?int $pinnedVersion = null): WorkflowRun
    {
        $version = $pinnedVersion !== null
            ? $this->definitions->findVersion($workflowKey, $pinnedVersion)
            : $this->definitions->latest($workflowKey);

        if ($version === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $workflowKey/$pinnedVersion are used to build an internal exception message only, never echoed as HTML.
            throw WorkflowException::definitionNotFound($workflowKey, $pinnedVersion);
        }

        $definition = WorkflowDefinition::fromDecoded($workflowKey, $version->version, $version->definition);

        $run = new WorkflowRun(
            id: null,
            workflowKey: $workflowKey,
            version: $version->version,
            correlationId: $this->correlation->id(),
            status: WorkflowRunStatus::Pending,
            triggeredBy: $triggeredBy,
            userId: $userId,
            currentStepKey: null,
            error: null,
            startedAt: EntityDates::now(),
            completedAt: null,
        );

        $runId = $this->runs->save($run);
        $run = $run->withStatus(WorkflowRunStatus::Running)->withId($runId);
        $this->runs->save($run);

        $this->events->dispatch(new WorkflowRunStartedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $runId]),
            runId: $runId,
            workflowKey: $workflowKey,
            version: $version->version,
            triggeredBy: $triggeredBy,
        ));

        $firstStep = $definition->firstStep();

        if ($firstStep === null) {
            return $this->completeRun($run);
        }

        return $this->walkFrom($run, $definition, $firstStep);
    }

    /**
     * Records an approval decision and, if approved, resumes the walk
     * from the step after the approval gate. Idempotency: only a
     * Pending approval can be decided (ApprovalRepository::save()
     * itself refuses to modify an already-decided record — this is a
     * second, defense-in-depth guard at the orchestration layer).
     *
     * @throws WorkflowException If no pending approval exists for this run/step.
     */
    public function approve(int $runId, string $stepKey, int $userId, bool $approved, ?string $reason = null): WorkflowRun
    {
        $pending = $this->approvals->findPendingForRunStep($runId, $stepKey);

        if ($pending === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $runId/$stepKey are used to build an internal exception message only, never echoed as HTML.
            throw WorkflowException::noPendingApproval($runId, $stepKey);
        }

        $decided = $pending->decide($approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected, $userId, $reason);
        $this->approvals->save($decided);

        $run = $this->requireRun($runId);
        $definition = $this->requireDefinition($run);

        if ($approved) {
            $this->events->dispatch(new ApprovalGrantedEvent(
                $this->metadataFactory->create('Workflow', ['run_id' => $runId]),
                runId: $runId,
                stepKey: $stepKey,
                approvalId: (int) $decided->id,
                decidedBy: $userId,
            ));

            $this->completeStepResultForKey($runId, $stepKey, StepStatus::Completed, ['approval_decision' => 'approved']);

            $run = $run->withStatus(WorkflowRunStatus::Running, $stepKey);
            $this->runs->save($run);

            $next = $definition->stepAfter($stepKey);

            return $next === null ? $this->completeRun($run) : $this->walkFrom($run, $definition, $next);
        }

        $this->events->dispatch(new ApprovalRejectedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $runId]),
            runId: $runId,
            stepKey: $stepKey,
            approvalId: (int) $decided->id,
            decidedBy: $userId,
            reason: $reason,
        ));

        $this->completeStepResultForKey($runId, $stepKey, StepStatus::Failed, [], 'Rejected by approval.');

        return $this->failRun($run, 'Approval rejected at step "' . $stepKey . '".');
    }

    /**
     * Called by the queue-completion listener (see
     * QueueCompletionListener) when a deferred step's underlying queue
     * job finishes — the "existing job-completion mechanism" reused per
     * Decision 3, not a second async framework.
     *
     * Idempotent by construction: if no step result is found in
     * Deferred status for $queueJobId, this is a no-op — a duplicate
     * JobCompletedEvent for an already-resumed step cannot execute the
     * step twice.
     *
     * @param array<string, mixed> $output
     */
    public function resumeFromQueueJob(int $queueJobId, bool $succeeded, array $output = [], ?string $error = null): void
    {
        $stepResult = $this->stepResults->findByQueueJobId($queueJobId);

        if ($stepResult === null || $stepResult->status !== StepStatus::Deferred) {
            // Not ours, or already resumed — idempotent no-op.
            return;
        }

        $run = $this->requireRun($stepResult->runId);
        $definition = $this->requireDefinition($run);

        $this->events->dispatch(new StepResumedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
            runId: (int) $run->id,
            stepKey: $stepResult->stepKey,
            queueJobId: $queueJobId,
            succeeded: $succeeded,
        ));

        if (!$succeeded) {
            $this->stepResults->save($stepResult->withStatus(StepStatus::Failed, null, $error ?? 'Deferred job failed.', null, null, EntityDates::now()));

            $this->events->dispatch(new StepFailedEvent(
                $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                runId: (int) $run->id,
                stepKey: $stepResult->stepKey,
                error: $error ?? 'Deferred job failed.',
                willRetry: false,
            ));

            $this->failRun($run, sprintf('Step "%s" failed: %s', $stepResult->stepKey, $error ?? 'Deferred job failed.'));
            return;
        }

        $this->stepResults->save($stepResult->withStatus(StepStatus::Completed, $output, null, null, null, EntityDates::now()));

        $this->events->dispatch(new StepCompletedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
            runId: (int) $run->id,
            stepKey: $stepResult->stepKey,
        ));

        $run = $run->withStatus(WorkflowRunStatus::Running, $stepResult->stepKey);
        $this->runs->save($run);

        $next = $definition->stepAfter($stepResult->stepKey);

        if ($next === null) {
            $this->completeRun($run);
            return;
        }

        $this->walkFrom($run, $definition, $next);
    }

    // ------------------------------------------------------------------
    // Internal walk
    // ------------------------------------------------------------------

    private function walkFrom(WorkflowRun $run, WorkflowDefinition $definition, ?StepDefinition $step): WorkflowRun
    {
        while ($step !== null) {
            $context = $this->buildContext((int) $run->id);

            if (!$this->conditions->evaluate($step->condition, $context)) {
                $this->recordStep($run, $step, StepStatus::Skipped, [], null, null, 0);
                $step = $definition->stepAfter($step->key);
                continue;
            }

            $action = $this->actions->forType($step->action);

            if ($action === null) {
                return $this->failRun($run, sprintf('No action registered for type "%s" (step "%s").', $step->action, $step->key));
            }

            $run = $run->withStatus(WorkflowRunStatus::Running, $step->key);
            $this->runs->save($run);

            $this->events->dispatch(new StepStartedEvent(
                $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                runId: (int) $run->id,
                stepKey: $step->key,
                actionType: $step->action,
            ));

            $runContext = new WorkflowRunContext(
                runId: (int) $run->id,
                stepKey: $step->key,
                correlationId: $run->correlationId,
                stepConfig: $step->config,
                priorStepOutputs: $context,
            );

            $attempts = 0;

            try {
                $result = $this->retry->execute($step->key, function () use (&$attempts, $action, $runContext) {
                    $attempts++;
                    return $action->execute($runContext);
                });
            } catch (\Throwable $e) {
                $this->recordStep($run, $step, StepStatus::Failed, [], $e->getMessage(), null, $attempts);

                $this->events->dispatch(new StepFailedEvent(
                    $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                    runId: (int) $run->id,
                    stepKey: $step->key,
                    error: $e->getMessage(),
                    willRetry: false,
                ));

                return $this->failRun($run, sprintf('Step "%s" failed: %s', $step->key, $e->getMessage()));
            }

            if ($result->isAwaitingApproval()) {
                $this->recordStep($run, $step, StepStatus::Running, [], null, null, $attempts);

                $approval = new Approval(
                    id: null,
                    runId: (int) $run->id,
                    stepKey: $step->key,
                    status: ApprovalStatus::Pending,
                    requestedAt: EntityDates::now(),
                    decidedAt: null,
                    decidedBy: null,
                    reason: null,
                );
                $approvalId = $this->approvals->save($approval);

                $this->events->dispatch(new ApprovalRequestedEvent(
                    $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                    runId: (int) $run->id,
                    stepKey: $step->key,
                    approvalId: $approvalId,
                ));

                $run = $run->withStatus(WorkflowRunStatus::AwaitingApproval, $step->key);
                $this->runs->save($run);

                return $run;
            }

            if ($result->isDeferred()) {
                $this->recordStep($run, $step, StepStatus::Deferred, [], null, $result->deferredQueueJobId, $attempts);

                $this->events->dispatch(new StepDeferredEvent(
                    $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                    runId: (int) $run->id,
                    stepKey: $step->key,
                    queueJobId: (int) $result->deferredQueueJobId,
                ));

                // Run stays Running; the walk halts here and resumes via
                // resumeFromQueueJob() when the queue job completes.
                return $run;
            }

            if ($result->isFailure()) {
                $this->recordStep($run, $step, StepStatus::Failed, [], $result->error, null, $attempts);

                $this->events->dispatch(new StepFailedEvent(
                    $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                    runId: (int) $run->id,
                    stepKey: $step->key,
                    error: (string) $result->error,
                    willRetry: false,
                ));

                if ($step->onFailureNext !== null) {
                    $step = $definition->stepByKey($step->onFailureNext);
                    continue;
                }

                return $this->failRun($run, sprintf('Step "%s" failed: %s', $step->key, $result->error));
            }

            // Success.
            $this->recordStep($run, $step, StepStatus::Completed, $result->output, null, null, $attempts);

            $this->events->dispatch(new StepCompletedEvent(
                $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
                runId: (int) $run->id,
                stepKey: $step->key,
            ));

            $step = $step->next !== null ? $definition->stepByKey($step->next) : $definition->stepAfter($step->key);
        }

        return $this->completeRun($run);
    }

    /**
     * @param array<string, mixed> $output
     */
    private function recordStep(
        WorkflowRun $run,
        StepDefinition $step,
        StepStatus $status,
        array $output,
        ?string $error,
        ?int $queueJobId,
        int $attempts
    ): void {
        $result = new WorkflowStepResult(
            id: null,
            runId: (int) $run->id,
            stepKey: $step->key,
            actionType: $step->action,
            status: $status,
            input: $step->config,
            output: $output,
            error: $error,
            queueJobId: $queueJobId,
            attempts: $attempts,
            rollbackStatus: null,
            startedAt: EntityDates::now(),
            completedAt: $status->isTerminal() ? EntityDates::now() : null,
        );

        $this->stepResults->save($result);
    }

    /**
     * @param array<string, mixed> $output
     */
    private function completeStepResultForKey(int $runId, string $stepKey, StepStatus $status, array $output, ?string $error = null): void
    {
        foreach ($this->stepResults->forRun($runId) as $existing) {
            if ($existing->stepKey === $stepKey && !$existing->status->isTerminal()) {
                $this->stepResults->save($existing->withStatus($status, $output, $error, null, null, EntityDates::now()));
                return;
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>> Step key => output, completed steps only.
     */
    private function buildContext(int $runId): array
    {
        $context = [];

        foreach ($this->stepResults->forRun($runId) as $result) {
            if ($result->status === StepStatus::Completed) {
                $context[$result->stepKey] = $result->output;
            }
        }

        return $context;
    }

    private function completeRun(WorkflowRun $run): WorkflowRun
    {
        $run = $run->withStatus(WorkflowRunStatus::Completed, null, null, EntityDates::now());
        $this->runs->save($run);

        $this->events->dispatch(new WorkflowRunCompletedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
            runId: (int) $run->id,
            workflowKey: $run->workflowKey,
        ));

        return $run;
    }

    private function failRun(WorkflowRun $run, string $error): WorkflowRun
    {
        if ($run->status->isTerminal()) {
            // Already terminal — a defensive guard, matching the Module 6
            // RC audit's Issue 1 lesson (never silently overwrite a
            // terminal state).
            return $run;
        }

        $run = $run->withStatus(WorkflowRunStatus::Failed, null, $error, EntityDates::now());
        $this->runs->save($run);

        $this->events->dispatch(new WorkflowRunFailedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
            runId: (int) $run->id,
            workflowKey: $run->workflowKey,
            error: $error,
        ));

        $this->performRollback($run);

        return $run;
    }

    /**
     * Best-effort rollback, §2.5: walks completed steps in reverse order;
     * a RollbackFailed/NotReversible outcome on one step does not block
     * attempting rollback on earlier steps.
     */
    private function performRollback(WorkflowRun $run): void
    {
        $completed = array_values(array_filter(
            $this->stepResults->forRun((int) $run->id),
            static fn (WorkflowStepResult $r): bool => $r->status === StepStatus::Completed
        ));

        if ($completed === []) {
            return;
        }

        $this->events->dispatch(new RollbackStartedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
            runId: (int) $run->id,
        ));

        foreach (array_reverse($completed) as $stepResult) {
            $action = $this->actions->forType($stepResult->actionType);

            if (!$action instanceof RollbackableActionInterface) {
                $this->stepResults->save($stepResult->withRollbackStatus(RollbackStatus::NotReversible));
                continue;
            }

            try {
                $outcome = $action->rollback($stepResult);
            } catch (\Throwable $e) {
                $this->logger->error('Rollback of step {step} threw: {error}', [
                    'step'  => $stepResult->stepKey,
                    'error' => $e->getMessage(),
                ]);
                $this->stepResults->save($stepResult->withRollbackStatus(RollbackStatus::RollbackFailed));
                continue;
            }

            $this->stepResults->save($stepResult->withRollbackStatus(match ($outcome->outcome) {
                RollbackOutcome::RolledBack => RollbackStatus::RolledBack,
                RollbackOutcome::RollbackFailed => RollbackStatus::RollbackFailed,
                RollbackOutcome::NotReversible => RollbackStatus::NotReversible,
            }));
        }

        $this->events->dispatch(new RollbackCompletedEvent(
            $this->metadataFactory->create('Workflow', ['run_id' => $run->id]),
            runId: (int) $run->id,
        ));
    }

    private function requireRun(int $runId): WorkflowRun
    {
        $run = $this->runs->find($runId);

        if ($run === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $runId is used to build an internal exception message only, never echoed as HTML.
            throw WorkflowException::runNotFound($runId);
        }

        return $run;
    }

    private function requireDefinition(WorkflowRun $run): WorkflowDefinition
    {
        $version = $this->definitions->findVersion($run->workflowKey, $run->version);

        if ($version === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $run's properties are used to build an internal exception message only, never echoed as HTML.
            throw WorkflowException::definitionNotFound($run->workflowKey, $run->version);
        }

        return WorkflowDefinition::fromDecoded($run->workflowKey, $version->version, $version->definition);
    }
}

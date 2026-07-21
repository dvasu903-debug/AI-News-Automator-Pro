<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * A row in `ana_workflow_step_results` — one step's outcome within a
 * run. `queueJobId` is set only for a step that returned
 * ActionResult::deferred() and is the correlation key the queue-
 * completion listener uses to find and resume the right step (Decision
 * 3 — "resume logic must be idempotent": the listener checks
 * status === Deferred before resuming, so a duplicate JobCompletedEvent
 * for an already-Completed step is a no-op, not a double-execution).
 */
final class WorkflowStepResult
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $output
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly string $actionType,
        public readonly StepStatus $status,
        public readonly array $input,
        public readonly array $output,
        public readonly ?string $error,
        public readonly ?int $queueJobId,
        public readonly int $attempts,
        public readonly ?RollbackStatus $rollbackStatus,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            runId: (int) $row['run_id'],
            stepKey: (string) $row['step_key'],
            actionType: (string) $row['action_type'],
            status: StepStatus::from((string) $row['status']),
            input: is_string($row['input'] ?? null) ? (json_decode($row['input'], true) ?: []) : [],
            output: is_string($row['output'] ?? null) ? (json_decode($row['output'], true) ?: []) : [],
            error: $row['error'] !== null ? (string) $row['error'] : null,
            queueJobId: $row['queue_job_id'] !== null ? (int) $row['queue_job_id'] : null,
            attempts: (int) ($row['attempts'] ?? 0),
            rollbackStatus: $row['rollback_status'] !== null ? RollbackStatus::from((string) $row['rollback_status']) : null,
            startedAt: EntityDates::fromMysql((string) $row['started_at']),
            completedAt: EntityDates::nullableFromMysql($row['completed_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'run_id'          => $this->runId,
            'step_key'        => $this->stepKey,
            'action_type'     => $this->actionType,
            'status'          => $this->status->value,
            'input'           => wp_json_encode($this->input) ?: '{}',
            'output'          => wp_json_encode($this->output) ?: '{}',
            'error'           => $this->error,
            'queue_job_id'    => $this->queueJobId,
            'attempts'        => $this->attempts,
            'rollback_status' => $this->rollbackStatus?->value,
            'started_at'      => EntityDates::toMysql($this->startedAt),
            'completed_at'    => EntityDates::nullableToMysql($this->completedAt),
        ];
    }

    /**
     * @param array<string, mixed>|null $output
     */
    public function withStatus(
        StepStatus $status,
        ?array $output = null,
        ?string $error = null,
        ?int $queueJobId = null,
        ?int $attempts = null,
        ?\DateTimeImmutable $completedAt = null
    ): self {
        return new self(
            id: $this->id,
            runId: $this->runId,
            stepKey: $this->stepKey,
            actionType: $this->actionType,
            status: $status,
            input: $this->input,
            output: $output ?? $this->output,
            error: $error ?? $this->error,
            queueJobId: $queueJobId ?? $this->queueJobId,
            attempts: $attempts ?? $this->attempts,
            rollbackStatus: $this->rollbackStatus,
            startedAt: $this->startedAt,
            completedAt: $completedAt ?? $this->completedAt,
        );
    }

    public function withRollbackStatus(RollbackStatus $status): self
    {
        return new self(
            id: $this->id,
            runId: $this->runId,
            stepKey: $this->stepKey,
            actionType: $this->actionType,
            status: $this->status,
            input: $this->input,
            output: $this->output,
            error: $this->error,
            queueJobId: $this->queueJobId,
            attempts: $this->attempts,
            rollbackStatus: $status,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
        );
    }
}

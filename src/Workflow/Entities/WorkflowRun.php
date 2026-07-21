<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * A row in `ana_workflow_runs` — one execution instance. Records the
 * exact (workflow_key, version) it executed, resolved once at trigger
 * time and never re-resolved mid-run (§2.7) — this is what makes
 * "never overwrite history" operationally meaningful: a definition can
 * gain a new version while this run is still in flight, and this run
 * remains fully explainable against the version it actually ran.
 */
final class WorkflowRun
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $workflowKey,
        public readonly int $version,
        public readonly string $correlationId,
        public readonly WorkflowRunStatus $status,
        public readonly string $triggeredBy,
        public readonly ?int $userId,
        public readonly ?string $currentStepKey,
        public readonly ?string $error,
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
            workflowKey: (string) $row['workflow_key'],
            version: (int) $row['version'],
            correlationId: (string) $row['run_correlation_id'],
            status: WorkflowRunStatus::from((string) $row['status']),
            triggeredBy: (string) $row['triggered_by'],
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            currentStepKey: $row['current_step_key'] !== null ? (string) $row['current_step_key'] : null,
            error: $row['error'] !== null ? (string) $row['error'] : null,
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
            'workflow_key'       => $this->workflowKey,
            'version'            => $this->version,
            'run_correlation_id' => $this->correlationId,
            'status'             => $this->status->value,
            'triggered_by'       => $this->triggeredBy,
            'user_id'            => $this->userId,
            'current_step_key'   => $this->currentStepKey,
            'error'              => $this->error,
            'started_at'         => EntityDates::toMysql($this->startedAt),
            'completed_at'       => EntityDates::nullableToMysql($this->completedAt),
        ];
    }

    /** Used once, immediately after the initial insert, to attach the generated id. */
    public function withId(int $id): self
    {
        return new self(
            id: $id,
            workflowKey: $this->workflowKey,
            version: $this->version,
            correlationId: $this->correlationId,
            status: $this->status,
            triggeredBy: $this->triggeredBy,
            userId: $this->userId,
            currentStepKey: $this->currentStepKey,
            error: $this->error,
            startedAt: $this->startedAt,
            completedAt: $this->completedAt,
        );
    }

    public function withStatus(
        WorkflowRunStatus $status,
        ?string $currentStepKey = null,
        ?string $error = null,
        ?\DateTimeImmutable $completedAt = null
    ): self {
        return new self(
            id: $this->id,
            workflowKey: $this->workflowKey,
            version: $this->version,
            correlationId: $this->correlationId,
            status: $status,
            triggeredBy: $this->triggeredBy,
            userId: $this->userId,
            currentStepKey: $currentStepKey ?? $this->currentStepKey,
            error: $error ?? $this->error,
            startedAt: $this->startedAt,
            completedAt: $completedAt ?? $this->completedAt,
        );
    }
}

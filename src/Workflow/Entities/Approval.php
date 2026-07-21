<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * A row in `ana_workflow_approvals` — a human approval gate. Once
 * decidedAt is non-null, the record is immutable (Part 5 — "no update
 * path on a resolved approval record"); ApprovalRepository's save()
 * only ever inserts a fresh row, WorkflowRunner never re-saves an
 * already-decided Approval.
 */
final class Approval
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $runId,
        public readonly string $stepKey,
        public readonly ApprovalStatus $status,
        public readonly \DateTimeImmutable $requestedAt,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?int $decidedBy,
        public readonly ?string $reason,
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
            status: ApprovalStatus::from((string) $row['status']),
            requestedAt: EntityDates::fromMysql((string) $row['requested_at']),
            decidedAt: EntityDates::nullableFromMysql($row['decided_at'] ?? null),
            decidedBy: $row['decided_by'] !== null ? (int) $row['decided_by'] : null,
            reason: $row['reason'] !== null ? (string) $row['reason'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'run_id'       => $this->runId,
            'step_key'     => $this->stepKey,
            'status'       => $this->status->value,
            'requested_at' => EntityDates::toMysql($this->requestedAt),
            'decided_at'   => EntityDates::nullableToMysql($this->decidedAt),
            'decided_by'   => $this->decidedBy,
            'reason'       => $this->reason,
        ];
    }

    public function decide(ApprovalStatus $status, int $decidedBy, ?string $reason = null): self
    {
        return new self(
            id: $this->id,
            runId: $this->runId,
            stepKey: $this->stepKey,
            status: $status,
            requestedAt: $this->requestedAt,
            decidedAt: EntityDates::now(),
            decidedBy: $decidedBy,
            reason: $reason,
        );
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

use AINewsAutomator\Workflow\Entities\Approval;

interface ApprovalRepositoryInterface
{
    public function find(int $id): ?Approval;

    public function findPendingForRunStep(int $runId, string $stepKey): ?Approval;

    /**
     * Inserts a new approval record when $approval->id is null. Once a
     * decision is recorded (decidedAt is set), the record is immutable —
     * callers must not call save() again on an already-decided approval
     * (WorkflowRunner enforces this, matching the requirement's "audit
     * every decision, no update path on a resolved record").
     */
    public function save(Approval $approval): int;
}

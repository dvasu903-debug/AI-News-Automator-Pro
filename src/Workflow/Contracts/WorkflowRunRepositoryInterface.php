<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

use AINewsAutomator\Workflow\Entities\WorkflowRun;
use AINewsAutomator\Workflow\Entities\WorkflowRunStatus;

interface WorkflowRunRepositoryInterface
{
    public function find(int $id): ?WorkflowRun;

    public function findByCorrelationId(string $correlationId): ?WorkflowRun;

    /**
     * Persists a run. Insert when $run->id is null, otherwise a full
     * update (status transitions are guarded by WorkflowRunner before
     * calling this — the repository itself does not enforce transition
     * rules, matching AbstractRepository's validate()-not-state-machine
     * scope used by every prior repository).
     */
    public function save(WorkflowRun $run): int;

    /**
     * @return list<WorkflowRun>
     */
    public function byStatus(WorkflowRunStatus $status, int $limit = 50): array;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

use AINewsAutomator\Workflow\Entities\WorkflowStepResult;

interface WorkflowStepResultRepositoryInterface
{
    public function find(int $id): ?WorkflowStepResult;

    /**
     * @return list<WorkflowStepResult> In execution order for the run.
     */
    public function forRun(int $runId): array;

    /** Finds the step result currently deferred on the given queue job, if any. */
    public function findByQueueJobId(int $queueJobId): ?WorkflowStepResult;

    public function save(WorkflowStepResult $result): int;
}

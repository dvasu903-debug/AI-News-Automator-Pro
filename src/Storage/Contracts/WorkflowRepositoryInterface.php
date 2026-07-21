<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\WorkflowRecord;

interface WorkflowRepositoryInterface
{
    public function find(int $id): ?WorkflowRecord;

    /**
     * @return list<WorkflowRecord>
     */
    public function forVertical(string $vertical, bool $enabledOnly = true): array;

    public function save(WorkflowRecord $workflow): int;

    public function delete(int $id): bool;
}

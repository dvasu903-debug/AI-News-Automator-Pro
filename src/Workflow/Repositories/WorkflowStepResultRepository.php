<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Repositories;

use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\SortOrder;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use AINewsAutomator\Workflow\Contracts\WorkflowStepResultRepositoryInterface;
use AINewsAutomator\Workflow\Entities\WorkflowStepResult;

/**
 * @extends AbstractRepository<WorkflowStepResult>
 */
final class WorkflowStepResultRepository extends AbstractRepository implements WorkflowStepResultRepositoryInterface
{
    protected function table(): string
    {
        return 'workflow_step_results';
    }

    protected function hydrate(array $row): WorkflowStepResult
    {
        return WorkflowStepResult::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var WorkflowStepResult $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var WorkflowStepResult $entity */
        $errors = [];

        if (trim($entity->stepKey) === '') {
            $errors['step_key'] = 'Step key is required.';
        }

        if (trim($entity->actionType) === '') {
            $errors['action_type'] = 'Action type is required.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $errors is used to build an internal validation-exception message only, never echoed as HTML.
            throw new ValidationException($errors, 'Workflow step result failed validation.');
        }
    }

    public function find(int $id): ?WorkflowStepResult
    {
        return $this->findRow($id);
    }

    public function forRun(int $runId): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('run_id', $runId))
            ->orderBy(SortOrder::asc('id'))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function findByQueueJobId(int $queueJobId): ?WorkflowStepResult
    {
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('queue_job_id', $queueJobId))
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(WorkflowStepResult $result): int
    {
        if ($result->id === null) {
            return $this->insertRow($result);
        }

        $this->validate($result);
        $this->updateRow($this->dehydrate($result), ['id' => $result->id]);

        return $result->id;
    }
}

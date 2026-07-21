<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Repositories;

use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\SortOrder;
use AINewsAutomator\Storage\Repositories\AbstractRepository;
use AINewsAutomator\Workflow\Contracts\WorkflowRunRepositoryInterface;
use AINewsAutomator\Workflow\Entities\WorkflowRun;
use AINewsAutomator\Workflow\Entities\WorkflowRunStatus;

/**
 * @extends AbstractRepository<WorkflowRun>
 */
final class WorkflowRunRepository extends AbstractRepository implements WorkflowRunRepositoryInterface
{
    protected function table(): string
    {
        return 'workflow_runs';
    }

    protected function hydrate(array $row): WorkflowRun
    {
        return WorkflowRun::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var WorkflowRun $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var WorkflowRun $entity */
        $errors = [];

        if (trim($entity->workflowKey) === '') {
            $errors['workflow_key'] = 'Workflow key is required.';
        }

        if (trim($entity->correlationId) === '') {
            $errors['run_correlation_id'] = 'Correlation id is required.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $errors is used to build an internal validation-exception message only, never echoed as HTML.
            throw new ValidationException($errors, 'Workflow run failed validation.');
        }
    }

    public function find(int $id): ?WorkflowRun
    {
        return $this->findRow($id);
    }

    public function findByCorrelationId(string $correlationId): ?WorkflowRun
    {
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('run_correlation_id', $correlationId))
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(WorkflowRun $run): int
    {
        if ($run->id === null) {
            return $this->insertRow($run);
        }

        $this->validate($run);
        $this->updateRow($this->dehydrate($run), ['id' => $run->id]);

        return $run->id;
    }

    public function byStatus(WorkflowRunStatus $status, int $limit = 50): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('status', $status->value))
            ->orderBy(SortOrder::asc('started_at'))
            ->limit($limit)
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}

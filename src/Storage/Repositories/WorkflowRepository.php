<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\WorkflowRepositoryInterface;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\WorkflowRecord;
use AINewsAutomator\Storage\Events\WorkflowSavedEvent;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;

/**
 * @extends AbstractRepository<WorkflowRecord>
 */
final class WorkflowRepository extends AbstractRepository implements WorkflowRepositoryInterface
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::WORKFLOWS;
    }

    protected function hydrate(array $row): WorkflowRecord
    {
        return WorkflowRecord::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var WorkflowRecord $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var WorkflowRecord $entity */
        $errors = [];

        if (trim($entity->name) === '') {
            $errors['name'] = 'Name is required.';
        }

        if (trim($entity->vertical) === '') {
            $errors['vertical'] = 'Vertical is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Workflow failed validation.');
        }
    }

    public function find(int $id): ?WorkflowRecord
    {
        return $this->findRow($id);
    }

    public function forVertical(string $vertical, bool $enabledOnly = true): array
    {
        $filters = [Filter::equals('vertical', $vertical)];
        if ($enabledOnly) {
            $filters[] = Filter::equals('enabled', 1);
        }

        $rows = $this->connection->newQuery($this->table())->whereAll($filters)->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function save(WorkflowRecord $workflow): int
    {
        $wasCreated = $workflow->id === null;
        $now = EntityDates::now();

        $toSave = new WorkflowRecord(
            id: $workflow->id,
            name: $workflow->name,
            vertical: $workflow->vertical,
            definition: $workflow->definition,
            enabled: $workflow->enabled,
            createdAt: $workflow->createdAt->getTimestamp() === 0 ? $now : $workflow->createdAt,
            updatedAt: $now,
        );

        if ($wasCreated) {
            $id = $this->insertRow($toSave);
        } else {
            $this->validate($toSave);
            $this->updateRow($this->dehydrate($toSave), ['id' => $workflow->id]);
            $id = (int) $workflow->id;
        }

        $this->events->dispatch(new WorkflowSavedEvent(
            $this->metadataFactory->create('Storage', ['workflow_id' => $id]),
            workflowId: $id,
            vertical: $workflow->vertical,
            wasCreated: $wasCreated,
        ));

        return $id;
    }

    public function delete(int $id): bool
    {
        return $this->deleteRow($id) > 0;
    }
}

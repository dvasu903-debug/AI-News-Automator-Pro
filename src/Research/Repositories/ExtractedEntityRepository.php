<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Repositories;

use AINewsAutomator\Research\Contracts\ExtractedEntityRepositoryInterface;
use AINewsAutomator\Research\Entities\ExtractedEntity;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * @extends AbstractRepository<ExtractedEntity>
 */
final class ExtractedEntityRepository extends AbstractRepository implements ExtractedEntityRepositoryInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'research_entities';
    }

    protected function hydrate(array $row): ExtractedEntity
    {
        return ExtractedEntity::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var ExtractedEntity $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var ExtractedEntity $entity */
        $errors = [];

        if ($entity->sessionId <= 0) {
            $errors['session_id'] = 'A valid session id is required.';
        }

        if (trim($entity->name) === '') {
            $errors['name'] = 'Entity name is required.';
        }

        if (trim($entity->entityType) === '') {
            $errors['entity_type'] = 'Entity type is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Extracted entity failed validation.');
        }
    }

    public function recordOrIncrement(int $sessionId, string $name, string $entityType): int
    {
        $existing = $this->connection->newQuery($this->table())
            ->whereAll([
                Filter::equals('session_id', $sessionId),
                Filter::equals('name', $name),
                Filter::equals('entity_type', $entityType),
            ])
            ->first();

        if ($existing !== null) {
            $entity = $this->hydrate($existing)->withIncrementedMentionCount();
            $this->updateRow(['mention_count' => $entity->mentionCount], ['id' => $entity->id]);

            return (int) $entity->id;
        }

        $entity = new ExtractedEntity(null, $sessionId, $name, $entityType, 1, EntityDates::now());

        return $this->insertRow($entity);
    }

    public function forSession(int $sessionId): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('session_id', $sessionId))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}

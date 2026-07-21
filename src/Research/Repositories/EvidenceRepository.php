<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Repositories;

use AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface;
use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * @extends AbstractRepository<Evidence>
 */
final class EvidenceRepository extends AbstractRepository implements EvidenceRepositoryInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'research_evidence';
    }

    protected function hydrate(array $row): Evidence
    {
        return Evidence::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var Evidence $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var Evidence $entity */
        $errors = [];

        if ($entity->sessionId <= 0) {
            $errors['session_id'] = 'A valid session id is required.';
        }

        if (trim($entity->sourceUrl) === '' || filter_var($entity->sourceUrl, FILTER_VALIDATE_URL) === false) {
            $errors['source_url'] = 'A valid source URL is required.';
        }

        if (trim($entity->domain) === '') {
            $errors['domain'] = 'Domain is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Evidence failed validation.');
        }
    }

    public function record(Evidence $evidence): int
    {
        return $this->insertRow($evidence);
    }

    public function forSession(int $sessionId): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('session_id', $sessionId))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function find(int $id): ?Evidence
    {
        return $this->findRow($id);
    }
}

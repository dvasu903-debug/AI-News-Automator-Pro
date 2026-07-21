<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Repositories;

use AINewsAutomator\Research\Contracts\ContradictionRepositoryInterface;
use AINewsAutomator\Research\Entities\Contradiction;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * @extends AbstractRepository<Contradiction>
 */
final class ContradictionRepository extends AbstractRepository implements ContradictionRepositoryInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'research_contradictions';
    }

    protected function hydrate(array $row): Contradiction
    {
        return Contradiction::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var Contradiction $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var Contradiction $entity */
        $errors = [];

        if ($entity->sessionId <= 0) {
            $errors['session_id'] = 'A valid session id is required.';
        }

        if ($entity->claimAId <= 0 || $entity->claimBId <= 0) {
            $errors['claim_ids'] = 'Two valid claim ids are required.';
        }

        if ($entity->claimAId === $entity->claimBId) {
            $errors['claim_ids'] = 'A claim cannot contradict itself.';
        }

        if (trim($entity->description) === '') {
            $errors['description'] = 'A description of the contradiction is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Contradiction failed validation.');
        }
    }

    public function record(Contradiction $contradiction): int
    {
        return $this->insertRow($contradiction);
    }

    public function resolve(int $contradictionId): void
    {
        $this->updateRow(['resolved' => 1], ['id' => $contradictionId]);
    }

    public function forSession(int $sessionId, bool $unresolvedOnly = false): array
    {
        $filters = [Filter::equals('session_id', $sessionId)];

        if ($unresolvedOnly) {
            $filters[] = Filter::equals('resolved', 0);
        }

        $rows = $this->connection->newQuery($this->table())
            ->whereAll($filters)
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Repositories;

use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Research\Session\ResearchSummaryBuilder;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Repositories\AbstractRepository;

/**
 * @extends AbstractRepository<ResearchSession>
 */
final class SessionRepository extends AbstractRepository implements SessionRepositoryInterface
{
    public function __construct(
        ConnectionInterface $connection,
        private readonly ResearchSummaryBuilder $summaryBuilder,
    ) {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return 'research_sessions';
    }

    protected function hydrate(array $row): ResearchSession
    {
        return ResearchSession::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var ResearchSession $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var ResearchSession $entity */
        $errors = [];

        if (trim($entity->topic) === '') {
            $errors['topic'] = 'Topic is required.';
        }

        if (trim($entity->correlationId) === '') {
            $errors['correlation_id'] = 'Correlation id is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Research session failed validation.');
        }
    }

    public function find(int $id): ?ResearchSession
    {
        return $this->findRow($id);
    }

    public function findByCorrelationId(string $correlationId): ?ResearchSession
    {
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('correlation_id', $correlationId))
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(ResearchSession $session): int
    {
        if ($session->id === null) {
            return $this->insertRow($session);
        }

        $this->validate($session);
        $this->updateRow($this->dehydrate($session), ['id' => $session->id]);

        return $session->id;
    }

    public function byStatus(SessionStatus $status, int $limit = 25): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->where(Filter::equals('status', $status->value))
            ->limit($limit)
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function summarize(int $sessionId): ResearchSummary
    {
        $session = $this->find($sessionId);

        if ($session === null) {
            throw new SessionStateException(sprintf('Research session %d not found.', $sessionId));
        }

        if ($session->status !== SessionStatus::Completed) {
            throw SessionStateException::notGathering($sessionId, $session->status->value);
        }

        return $this->summaryBuilder->build($session);
    }
}

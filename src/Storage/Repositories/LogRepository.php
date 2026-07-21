<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\LogRepositoryInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Database\BatchPurger;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\LogEntry;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Logging\LogLevelValidator;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;
use AINewsAutomator\Storage\Query\SortOrder;

/**
 * @extends AbstractRepository<LogEntry>
 */
final class LogRepository extends AbstractRepository implements LogRepositoryInterface, PurgeableInterface
{
    public function __construct(ConnectionInterface $connection)
    {
        parent::__construct($connection);
    }

    protected function table(): string
    {
        return Tables::LOGS;
    }

    protected function hydrate(array $row): LogEntry
    {
        return LogEntry::fromRow($row);
    }

    protected function dehydrate(mixed $entity): array
    {
        /** @var LogEntry $entity */
        return $entity->toRow();
    }

    protected function validate(mixed $entity): void
    {
        /** @var LogEntry $entity */
        if (!LogLevelValidator::isValid($entity->level)) {
            throw new ValidationException(
                ['level' => 'Unrecognized log level: ' . $entity->level],
                'Log entry failed validation.'
            );
        }
    }

    public function persist(string $level, string $message, array $context, ?string $correlationId): void
    {
        $entry = new LogEntry(
            id: null,
            level: $level,
            message: $message,
            context: $context,
            correlationId: $correlationId,
            createdAt: EntityDates::now(),
        );

        $this->insertRow($entry);
    }

    public function recent(int $limit = 50): array
    {
        $rows = $this->connection->newQuery($this->table())
            ->orderBy(SortOrder::desc('id'))
            ->limit($limit)
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows);
    }

    public function paginate(array $filters, int $page = 1, int $perPage = 50): PageResult
    {
        return $this->paginateRows($filters, [SortOrder::desc('id')], $page, $perPage);
    }

    public function purgeOlderThan(int $days): int
    {
        return BatchPurger::purgeOlderThan($this->connection, $this->table(), 'created_at', $days);
    }
}

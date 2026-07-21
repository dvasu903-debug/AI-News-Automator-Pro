<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Exceptions\RepositoryException;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;
use AINewsAutomator\Storage\Query\SortOrder;

/**
 * Shared scaffolding for every repository: pagination, filtering,
 * bulk-insert, and the validate-before-persist hook. A concrete repository
 * supplies only its logical table name and DTO hydration
 * (fromRow()/toRow(), already on each entity) — this is what satisfies
 * "no duplicated SQL, no duplicated repository logic" as an actual
 * guarantee rather than a convention every repository has to remember.
 *
 * @template TEntity
 */
abstract class AbstractRepository
{
    public function __construct(protected readonly ConnectionInterface $connection)
    {
    }

    /** Logical table name (see Database\Tables). */
    abstract protected function table(): string;

    /**
     * Maps a raw DB row to the entity DTO.
     *
     * @param array<string, mixed> $row
     * @return TEntity
     */
    abstract protected function hydrate(array $row): mixed;

    /**
     * Maps an entity DTO to a raw row for persistence.
     *
     * @param TEntity $entity
     * @return array<string, mixed>
     */
    abstract protected function dehydrate(mixed $entity): array;

    /**
     * Validates an entity before insert/update. Default: no-op. Concrete
     * repositories override this to enforce domain rules (required
     * fields, known enum values, sane lengths) — requirement 6 from the
     * approved implementation plan: validation happens here, at the
     * single choke point every write passes through, not scattered across
     * callers.
     *
     * @param TEntity $entity
     * @throws ValidationException
     */
    protected function validate(mixed $entity): void
    {
        // Default: no validation. Overridden per repository as needed.
    }

    /**
     * @param list<Filter> $filters
     * @param list<SortOrder> $sorts
     * @return PageResult<TEntity>
     */
    protected function paginateRows(array $filters, array $sorts, int $page, int $perPage, bool $withCount = true): PageResult
    {
        $query = $this->connection->newQuery($this->table())->whereAll($filters);

        foreach ($sorts as $sort) {
            $query = $query->orderBy($sort);
        }

        $result = $withCount ? $query->paginate($page, $perPage) : $query->simplePaginate($page, $perPage);

        $items = array_map(fn (array $row) => $this->hydrate($row), $result->items);

        return new PageResult($items, $result->page, $result->perPage, $result->total, $result->totalPages, $result->hasMore);
    }

    /**
     * @return TEntity|null
     */
    protected function findRow(int $id): mixed
    {
        $row = $this->connection->newQuery($this->table())
            ->where(Filter::equals('id', $id))
            ->first();

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @return TEntity
     * @throws RepositoryException If no row exists for the id.
     */
    protected function findRowOrFail(int $id): mixed
    {
        $entity = $this->findRow($id);

        if ($entity === null) {
            throw RepositoryException::notFound(static::class, $id);
        }

        return $entity;
    }

    /**
     * @param TEntity $entity
     */
    protected function insertRow(mixed $entity): int
    {
        $this->validate($entity);
        return $this->connection->insert($this->table(), $this->dehydrate($entity));
    }

    /**
     * Bulk insert — requirement 7 (support bulk operations). Validates
     * every entity before any row is written.
     *
     * @param list<TEntity> $entities
     */
    protected function insertRows(array $entities): int
    {
        if ($entities === []) {
            return 0;
        }

        foreach ($entities as $entity) {
            $this->validate($entity);
        }

        $rows = array_map(fn ($entity) => $this->dehydrate($entity), $entities);

        return $this->connection->insertMany($this->table(), $rows);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    protected function updateRow(array $data, array $where): int
    {
        return $this->connection->update($this->table(), $data, $where);
    }

    protected function deleteRow(int $id): int
    {
        return $this->connection->delete($this->table(), ['id' => $id]);
    }
}

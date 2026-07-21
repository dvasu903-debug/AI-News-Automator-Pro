<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Database;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\QueryBuilderInterface;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\FilterOperator;
use AINewsAutomator\Storage\Query\PageResult;
use AINewsAutomator\Storage\Query\SortOrder;

/**
 * Fluent SQL composer. Column names are validated against a strict
 * allowlist pattern (alphanumeric + underscore only) before being
 * interpolated as identifiers — MySQL's prepared-statement placeholders
 * cannot parameterize identifiers (column/table names), only values, so
 * identifier safety here is enforced by pattern validation rather than
 * binding. Every VALUE (filter values, limit/offset) is always bound as a
 * parameter via Connection's $wpdb->prepare() call — never concatenated.
 *
 * toSql() (compose without executing) is what makes this class unit-
 * testable without a database: assert the SQL string + params array for
 * a given chain of where()/orderBy()/paginate() calls.
 */
final class QueryBuilder implements QueryBuilderInterface
{
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /** @var list<Filter> */
    private array $filters = [];

    /** @var list<SortOrder> */
    private array $sorts = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    /** @var list<string>|null */
    private ?array $selectColumns = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tableName,
    ) {
    }

    public function where(Filter $filter): self
    {
        $this->assertIdentifier($filter->column);
        $clone = clone $this;
        $clone->filters[] = $filter;
        return $clone;
    }

    public function whereAll(array $filters): self
    {
        $clone = clone $this;
        foreach ($filters as $filter) {
            $this->assertIdentifier($filter->column);
            $clone->filters[] = $filter;
        }
        return $clone;
    }

    public function orderBy(SortOrder $order): self
    {
        $this->assertIdentifier($order->column);
        $clone = clone $this;
        $clone->sorts[] = $order;
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limitValue = max(0, $limit);
        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offsetValue = max(0, $offset);
        return $clone;
    }

    public function select(array $columns): self
    {
        foreach ($columns as $column) {
            $this->assertIdentifier($column);
        }
        $clone = clone $this;
        $clone->selectColumns = $columns;
        return $clone;
    }

    public function get(): array
    {
        ['sql' => $sql, 'params' => $params] = $this->toSql();
        return $this->connection->select($sql, $params);
    }

    public function first(): ?array
    {
        $limited = $this->limit(1);
        ['sql' => $sql, 'params' => $params] = $limited->toSql();
        return $this->connection->selectOne($sql, $params);
    }

    public function count(): int
    {
        [$whereSql, $params] = $this->buildWhere();
        $sql = sprintf('SELECT COUNT(*) FROM `%s`%s', $this->tableName, $whereSql);
        return (int) $this->connection->scalar($sql, $params);
    }

    public function paginate(int $page, int $perPage): PageResult
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $this->count();
        $totalPages = (int) ceil($total / $perPage);

        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return new PageResult($items, $page, $perPage, $total, $totalPages, null);
    }

    public function simplePaginate(int $page, int $perPage): PageResult
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        // Fetch one extra row to cheaply determine "has more" without COUNT(*).
        $items = $this->limit($perPage + 1)->offset(($page - 1) * $perPage)->get();

        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            array_pop($items);
        }

        return new PageResult($items, $page, $perPage, null, null, $hasMore);
    }

    public function toSql(): array
    {
        $columns = $this->selectColumns !== null
            ? implode(', ', array_map(static fn (string $c): string => "`{$c}`", $this->selectColumns))
            : '*';

        [$whereSql, $params] = $this->buildWhere();

        $sql = sprintf('SELECT %s FROM `%s`%s', $columns, $this->tableName, $whereSql);

        if ($this->sorts !== []) {
            $orderParts = array_map(
                static fn (SortOrder $s): string => sprintf('`%s` %s', $s->column, $s->direction->value),
                $this->sorts
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        if ($this->limitValue !== null) {
            $sql .= ' LIMIT %d';
            $params[] = $this->limitValue;

            if ($this->offsetValue !== null) {
                $sql .= ' OFFSET %d';
                $params[] = $this->offsetValue;
            }
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(): array
    {
        if ($this->filters === []) {
            return ['', []];
        }

        $clauses = [];
        $params = [];

        foreach ($this->filters as $filter) {
            [$clause, $filterParams] = $this->buildClause($filter);
            $clauses[] = $clause;
            array_push($params, ...$filterParams);
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildClause(Filter $filter): array
    {
        $column = "`{$filter->column}`";

        return match ($filter->operator) {
            FilterOperator::IsNull    => ["{$column} IS NULL", []],
            FilterOperator::IsNotNull => ["{$column} IS NOT NULL", []],
            FilterOperator::In => (function () use ($column, $filter): array {
                $values = is_array($filter->value) ? $filter->value : [$filter->value];
                if ($values === []) {
                    return ['1 = 0', []]; // IN () matches nothing.
                }
                $placeholders = implode(', ', array_fill(0, count($values), $this->placeholderFor($values[0])));
                return ["{$column} IN ({$placeholders})", array_values($values)];
            })(),
            FilterOperator::NotIn => (function () use ($column, $filter): array {
                $values = is_array($filter->value) ? $filter->value : [$filter->value];
                if ($values === []) {
                    return ['1 = 1', []]; // NOT IN () excludes nothing.
                }
                $placeholders = implode(', ', array_fill(0, count($values), $this->placeholderFor($values[0])));
                return ["{$column} NOT IN ({$placeholders})", array_values($values)];
            })(),
            FilterOperator::Between => (function () use ($column, $filter): array {
                $values = is_array($filter->value) ? array_values($filter->value) : [$filter->value, $filter->value];
                $ph = $this->placeholderFor($values[0]);
                return ["{$column} BETWEEN {$ph} AND {$ph}", [$values[0], $values[1] ?? $values[0]]];
            })(),
            FilterOperator::Like => ["{$column} LIKE %s", ['%' . $this->escapeLike((string) $filter->value) . '%']],
            default => ["{$column} {$filter->operator->value} " . $this->placeholderFor($filter->value), [$filter->value]],
        };
    }

    private function placeholderFor(mixed $value): string
    {
        return match (true) {
            is_int($value)   => '%d',
            is_float($value) => '%f',
            default           => '%s',
        };
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid column identifier "%s". Only alphanumeric characters and underscores are permitted.',
                $identifier
            ));
        }
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;
use AINewsAutomator\Storage\Query\SortOrder;

/**
 * Fluent query composer. Deliberately narrow — filter, sort, paginate on a
 * single table — not a general-purpose ORM with relations/joins. Every
 * terminal method (get/first/count/paginate) executes via the injected
 * Connection, which is the only place raw SQL actually runs; this
 * interface's job is composing a safe, parameterized query, which is what
 * makes it unit-testable without a database (assert the produced SQL +
 * params tuple).
 */
interface QueryBuilderInterface
{
    public function where(Filter $filter): self;

    /**
     * @param list<Filter> $filters
     */
    public function whereAll(array $filters): self;

    public function orderBy(SortOrder $order): self;

    public function limit(int $limit): self;

    public function offset(int $offset): self;

    /**
     * Restricts selected columns. Omit for `SELECT *`. Used to implement
     * lazy-loading of large payload columns (only select them when
     * explicitly requested).
     *
     * @param list<string> $columns
     */
    public function select(array $columns): self;

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array;

    public function count(): int;

    /**
     * Full pagination: runs a COUNT(*) query in addition to the page
     * query, populating total/totalPages on the result.
     */
    public function paginate(int $page, int $perPage): PageResult;

    /**
     * Cheap pagination: no COUNT(*) query. Fetches perPage+1 rows to
     * determine hasMore without counting the full result set. Use this
     * whenever the caller doesn't need a total (e.g. infinite-scroll UIs).
     */
    public function simplePaginate(int $page, int $perPage): PageResult;

    /**
     * Returns the SQL and bound parameters this builder would execute,
     * without executing it — the primary seam for offline unit testing
     * and for the query-profiling extension point (§ ProfilerInterface).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function toSql(): array;
}

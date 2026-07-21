<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Thin wrapper over $wpdb. This is the ONLY interface any Storage class
 * uses to reach the database — no repository, migration, or other Storage
 * class touches $wpdb directly except the concrete Connection
 * implementation itself. Every method routes through $wpdb->prepare();
 * string concatenation into SQL never happens anywhere behind this
 * interface.
 */
interface ConnectionInterface
{
    /**
     * Resolves a logical table name (see Database\Tables) to its physical
     * name, e.g. "queue" -> "wp_ana_queue".
     */
    public function table(string $logicalName): string;

    public function newQuery(string $logicalTable): QueryBuilderInterface;

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array;

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array;

    /**
     * @param list<mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed;

    /**
     * @param array<string, mixed> $data
     * @return int The inserted row's auto-increment id (0 if the table has none).
     */
    public function insert(string $logicalTable, array $data): int;

    /**
     * Multi-row insert as a single statement.
     *
     * @param list<array<string, mixed>> $rows All rows MUST share the same columns.
     */
    public function insertMany(string $logicalTable, array $rows): int;

    /**
     * Insert-or-update-on-duplicate-key, for atomic counter increments.
     * $incrementColumns lists which columns should be added to (not
     * overwritten) on conflict, e.g. ['value' => 5].
     *
     * @param array<string, mixed> $data
     * @param array<string, int|float> $incrementColumns
     */
    public function upsertIncrement(string $logicalTable, array $data, array $incrementColumns): void;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $logicalTable, array $data, array $where): int;

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $logicalTable, array $where): int;

    /**
     * @param list<mixed> $params
     */
    public function statement(string $sql, array $params = []): int;

    public function lastInsertId(): int;
}

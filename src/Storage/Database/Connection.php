<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Database;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\QueryBuilderInterface;
use AINewsAutomator\Storage\Contracts\QueryProfilerInterface;
use AINewsAutomator\Storage\Exceptions\StorageException;

/**
 * The only class in the Storage module (indeed, the only class in the
 * entire plugin) that touches $wpdb directly. Every query — whether built
 * by hand here or composed by QueryBuilder — is executed through
 * $wpdb->prepare() when it carries parameters; string concatenation of
 * values into SQL never happens.
 *
 * QueryBuilder emits SQL using $wpdb's own placeholder syntax (%s/%d/%f),
 * so no placeholder translation is needed here — the params array from
 * QueryBuilder::toSql() is passed straight through to prepare().
 */
final class Connection implements ConnectionInterface
{
    public function __construct(private readonly ?QueryProfilerInterface $profiler = null)
    {
    }

    public function table(string $logicalName): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ana_' . $logicalName;
    }

    public function newQuery(string $logicalTable): QueryBuilderInterface
    {
        return new QueryBuilder($this, $this->table($logicalTable));
    }

    public function select(string $sql, array $params = []): array
    {
        global $wpdb;

        $results = $this->timed($sql, $params, function () use ($wpdb, $sql, $params): array {
            $prepared = $this->prepare($sql, $params);
            /** @var list<array<string, mixed>>|null $rows */
            $rows = $wpdb->get_results($prepared, ARRAY_A);
            return $rows ?? [];
        });

        return $results;
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        global $wpdb;

        return $this->timed($sql, $params, function () use ($wpdb, $sql, $params): ?array {
            $prepared = $this->prepare($sql, $params);
            /** @var array<string, mixed>|null $row */
            $row = $wpdb->get_row($prepared, ARRAY_A);
            return $row ?: null;
        });
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        global $wpdb;

        return $this->timed($sql, $params, function () use ($wpdb, $sql, $params): mixed {
            $prepared = $this->prepare($sql, $params);
            return $wpdb->get_var($prepared);
        });
    }

    public function insert(string $logicalTable, array $data): int
    {
        global $wpdb;

        $table = $this->table($logicalTable);
        $formats = array_map([$this, 'formatFor'], array_values($data));

        $result = $wpdb->insert($table, $data, $formats);

        if ($result === false) {
            throw new StorageException(sprintf('Insert into "%s" failed: %s', $table, $wpdb->last_error));
        }

        return (int) $wpdb->insert_id;
    }

    public function insertMany(string $logicalTable, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        global $wpdb;
        $table = $this->table($logicalTable);

        $columns = array_keys($rows[0]);
        $columnSql = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));

        $valuePlaceholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $placeholders[] = $this->placeholderFor($value);
                $params[] = $value;
            }
            $valuePlaceholders[] = '(' . implode(', ', $placeholders) . ')';
        }

        $sql = sprintf('INSERT INTO `%s` (%s) VALUES %s', $table, $columnSql, implode(', ', $valuePlaceholders));

        return $this->statement($sql, $params);
    }

    public function upsertIncrement(string $logicalTable, array $data, array $incrementColumns): void
    {
        global $wpdb;
        $table = $this->table($logicalTable);

        $columns = array_keys($data);
        $columnSql = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $placeholders = implode(', ', array_map(fn ($v) => $this->placeholderFor($v), array_values($data)));
        $params = array_values($data);

        $updateParts = [];
        foreach ($incrementColumns as $column => $by) {
            $ph = $this->placeholderFor($by);
            $updateParts[] = "`{$column}` = `{$column}` + {$ph}";
            $params[] = $by;
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            $columnSql,
            $placeholders,
            implode(', ', $updateParts)
        );

        $this->statement($sql, $params);
    }

    public function update(string $logicalTable, array $data, array $where): int
    {
        global $wpdb;

        $table = $this->table($logicalTable);
        $dataFormats = array_map([$this, 'formatFor'], array_values($data));
        $whereFormats = array_map([$this, 'formatFor'], array_values($where));

        $result = $wpdb->update($table, $data, $where, $dataFormats, $whereFormats);

        if ($result === false) {
            throw new StorageException(sprintf('Update on "%s" failed: %s', $table, $wpdb->last_error));
        }

        return (int) $result;
    }

    public function delete(string $logicalTable, array $where): int
    {
        global $wpdb;

        $table = $this->table($logicalTable);
        $formats = array_map([$this, 'formatFor'], array_values($where));

        $result = $wpdb->delete($table, $where, $formats);

        if ($result === false) {
            throw new StorageException(sprintf('Delete on "%s" failed: %s', $table, $wpdb->last_error));
        }

        return (int) $result;
    }

    public function statement(string $sql, array $params = []): int
    {
        global $wpdb;

        return $this->timed($sql, $params, function () use ($wpdb, $sql, $params): int {
            $prepared = $this->prepare($sql, $params);
            $result = $wpdb->query($prepared);

            if ($result === false) {
                throw new StorageException(sprintf('Statement failed: %s', $wpdb->last_error));
            }

            return (int) $result;
        });
    }

    public function lastInsertId(): int
    {
        global $wpdb;
        return (int) $wpdb->insert_id;
    }

    /**
     * @param list<mixed> $params
     */
    private function prepare(string $sql, array $params): string
    {
        global $wpdb;

        // Only call prepare() when there are actual parameters — calling it
        // with an all-literal, code-generated query (no user input, no
        // placeholders) is unnecessary and some WP versions emit a
        // doing_it_wrong notice for a prepare() call with no placeholders.
        if ($params === []) {
            return $sql;
        }

        return $wpdb->prepare($sql, ...$params);
    }

    private function formatFor(mixed $value): string
    {
        return match (true) {
            is_int($value)  => '%d',
            is_float($value) => '%f',
            $value === null  => '%s',
            default           => '%s',
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

    /**
     * Wraps a query execution with the profiling extension point. With the
     * default NullQueryProfiler binding this adds negligible overhead (one
     * no-op call); a future Monitoring module can bind a real profiler
     * without any repository or QueryBuilder code changing.
     *
     * @template T
     * @param list<mixed> $params
     * @param callable(): T $work
     * @return T
     */
    private function timed(string $sql, array $params, callable $work): mixed
    {
        if ($this->profiler === null) {
            return $work();
        }

        $start = microtime(true);
        $result = $work();
        $durationMs = (microtime(true) - $start) * 1000;

        $this->profiler->recordQuery($sql, $params, $durationMs);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

/**
 * A minimal in-memory fake of $wpdb, sufficient to unit-test
 * SchemaInspector, MigrationRecorder, MigrationRunner, and repository
 * insert/select/update paths without a real database. Simulates just
 * enough: prefix, prepare() (naive placeholder substitution matching
 * %s/%d/%f), an in-memory table map, and the handful of $wpdb methods
 * Connection/SchemaInspector/MigrationRecorder/repositories call.
 *
 * Deliberately narrow — recognizes only the specific query shapes this
 * project's repositories produce (SHOW TABLES LIKE, simple single-table
 * SELECT with basic WHERE, INSERT, partial-column UPDATE by exact-match
 * WHERE), not a general SQL engine. Multi-table joins are explicitly out
 * of scope for this fake — those need the real WordPress+MySQL
 * integration environment noted in the module README.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $dbname = 'test';
    public string $last_error = '';
    public int $insert_id = 0;

    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, int> */
    private array $autoIncrement = [];

    public function createTable(string $tableName): void
    {
        $this->tables[$tableName] = [];
        $this->autoIncrement[$tableName] = 0;
    }

    public function tableExistsInFake(string $tableName): bool
    {
        return isset($this->tables[$tableName]);
    }

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $i = 0;
        $result = preg_replace_callback('/%[sdf]/', function (array $m) use ($args, &$i): string {
            $value = $args[$i] ?? '';
            $i++;
            return match ($m[0]) {
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                default => "'" . addslashes((string) $value) . "'",
            };
        }, $query);

        return $result ?? $query;
    }

    public function get_var(string $query): mixed
    {
        if (preg_match('/^SHOW TABLES LIKE \'(.+)\'$/', $query, $m) === 1) {
            return $this->tableExistsInFake($m[1]) ? $m[1] : null;
        }

        if (preg_match('/^SELECT MAX\(batch\) FROM `(.+)`$/', $query, $m) === 1) {
            $rows = $this->tables[$m[1]] ?? [];
            $max = null;
            foreach ($rows as $row) {
                $max = $max === null ? $row['batch'] : max($max, $row['batch']);
            }
            return $max;
        }

        if (preg_match('/^SELECT COUNT\(\*\) FROM `(.+)`/', $query, $m) === 1) {
            return count($this->tables[$m[1]] ?? []);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get_results(string $query, string $output = 'ARRAY_A'): array
    {
        if (preg_match('/^SELECT (.+) FROM `(.+?)`(?:\s+WHERE\s+(.+?))?(?:\s+ORDER BY.*)?(?:\s+LIMIT.*)?$/s', $query, $m) !== 1) {
            return [];
        }

        $table = $m[2];
        $rows = $this->tables[$table] ?? [];

        if (!empty($m[3])) {
            $rows = $this->applyWhere($rows, $m[3]);
        }

        return array_values($rows);
    }

    /**
     * Applies a narrow subset of WHERE clause support: single or
     * AND-joined `\`column\` = 'value'` / `\`column\` = 123` /
     * `\`column\` IN (...)` conditions — sufficient for this module's
     * findRow()/simple-filter test scenarios. Not a general SQL parser.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyWhere(array $rows, string $whereClause): array
    {
        $conditions = preg_split('/\s+AND\s+/', $whereClause) ?: [];

        foreach ($conditions as $condition) {
            if (preg_match('/^`?(\w+)`?\s*=\s*\'(.*)\'$/', $condition, $cm) === 1) {
                $rows = array_filter($rows, static fn (array $r): bool => (string) ($r[$cm[1]] ?? '') === $cm[2]);
            } elseif (preg_match('/^`?(\w+)`?\s*<=\s*\'(.*)\'$/', $condition, $cm) === 1) {
                // String <= comparison: correct for MySQL DATETIME literals
                // ('Y-m-d H:i:s' compares lexicographically). NULL never
                // matches, mirroring SQL three-valued logic.
                $rows = array_filter($rows, static fn (array $r): bool => isset($r[$cm[1]]) && $r[$cm[1]] !== null && (string) $r[$cm[1]] <= $cm[2]);
            } elseif (preg_match('/^`?(\w+)`?\s*=\s*(-?\d+)$/', $condition, $cm) === 1) {
                $rows = array_filter($rows, static fn (array $r): bool => (int) ($r[$cm[1]] ?? null) === (int) $cm[2]);
            } elseif (preg_match('/^`?(\w+)`?\s+IN\s+\((.+)\)$/', $condition, $cm) === 1) {
                $values = array_map(
                    static fn (string $v): string => trim($v, " '"),
                    explode(',', $cm[2])
                );
                $rows = array_filter($rows, static fn (array $r): bool => in_array((string) ($r[$cm[1]] ?? ''), $values, true));
            }
        }

        return array_values($rows);
    }

    public function get_row(string $query, string $output = 'ARRAY_A'): ?array
    {
        $results = $this->get_results($query, $output);
        return $results[0] ?? null;
    }

    /**
     * Real wpdb::query() executes arbitrary SQL and returns rows affected.
     * This fake recognizes exactly one raw-SQL shape produced by this
     * project — Connection::insertMany()'s multi-row
     * "INSERT INTO `table` (`col`, ...) VALUES (...), (...), ..." — and
     * actually stores the rows, since Connection::insertMany() (used by
     * AbstractRepository::insertRows()/addMany()) is a real, exercised
     * path, not a hypothetical one. Everything else returns 1 (assumed
     * success), matching this class's narrow, specific-shapes-only scope.
     *
     * Connection::upsertIncrement()'s "... ON DUPLICATE KEY UPDATE ..."
     * shape is NOT handled here — it isn't exercised by any current test,
     * same class of gap as this method's previous no-op behavior. Flagged
     * rather than fixed, per the smallest-fix-for-the-actual-failure rule.
     */
    public function query(string $query): int|false
    {
        if (preg_match('/^INSERT INTO `([^`]+)` \(([^)]+)\) VALUES\s+(.+)$/s', $query, $m) === 1) {
            return $this->fakeMultiRowInsert($m[1], $m[2], $m[3]);
        }

        return 1;
    }

    private function fakeMultiRowInsert(string $table, string $columnList, string $valuesList): int
    {
        $columns = array_map(
            static fn (string $c): string => trim($c, " `"),
            explode(',', $columnList)
        );

        $inserted = 0;

        foreach ($this->splitTopLevelTuples($valuesList) as $tuple) {
            $values = $this->splitTupleValues($tuple);

            if (count($values) !== count($columns)) {
                continue;
            }

            $row = array_combine($columns, $values);
            $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
            $row['id'] = $this->autoIncrement[$table];
            $this->tables[$table][] = $row;
            $this->insert_id = $row['id'];
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Splits "(a, b), (c, d)" into ["a, b", "c, d"], respecting
     * single-quoted string boundaries so a literal ')' or ',' inside a
     * value doesn't break the split.
     *
     * @return list<string>
     */
    private function splitTopLevelTuples(string $valuesList): array
    {
        $tuples = [];
        $depth = 0;
        $inQuote = false;
        $current = '';

        for ($i = 0; $i < strlen($valuesList); $i++) {
            $ch = $valuesList[$i];

            if ($ch === "'" && ($i === 0 || $valuesList[$i - 1] !== '\\')) {
                $inQuote = !$inQuote;
            }

            if (!$inQuote && $ch === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }

            if (!$inQuote && $ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $current;
                    $current = '';
                    continue;
                }
            }

            if ($depth >= 1) {
                $current .= $ch;
            }
        }

        return $tuples;
    }

    /**
     * @return list<int|float|string|null>
     */
    private function splitTupleValues(string $tuple): array
    {
        $values = [];
        $inQuote = false;
        $current = '';

        for ($i = 0; $i < strlen($tuple); $i++) {
            $ch = $tuple[$i];

            if ($ch === "'" && ($i === 0 || $tuple[$i - 1] !== '\\')) {
                $inQuote = !$inQuote;
                continue;
            }

            if (!$inQuote && $ch === ',') {
                $values[] = $this->castLiteral(trim($current));
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $values[] = $this->castLiteral(trim($current));

        return $values;
    }

    private function castLiteral(string $literal): int|float|string|null
    {
        if ($literal === 'NULL') {
            return null;
        }

        if (is_numeric($literal)) {
            return str_contains($literal, '.') ? (float) $literal : (int) $literal;
        }

        return stripslashes($literal);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int|false
    {
        $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
        $data['id'] = $this->autoIncrement[$table];
        $this->tables[$table][] = $data;
        $this->insert_id = $data['id'];
        return 1;
    }

    /**
     * Real wpdb semantics: merges $data into every row matching $where
     * (all key=>value pairs AND'd together, same as WordPress's own
     * update() building a WHERE clause from that array) — a partial
     * column update, not a row replacement. Returns the number of rows
     * matched (0 if none matched, same as real wpdb — that's not a
     * failure), or false only if the table itself doesn't exist
     * (analogous to a real SQL error against an unknown table).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    /**
     * Mirrors real wpdb::delete(): removes every row matching all $where
     * key/value pairs (AND'd), returns the number of rows removed. Added
     * when Connection::delete() became genuinely exercised (the stale-lock
     * reclaim's exhausted-job path, plus markSuccess) — previously a
     * known, flagged, unexercised gap.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int|false
    {
        $rows = $this->tables[$table] ?? [];
        $kept = [];
        $removed = 0;

        foreach ($rows as $row) {
            if ($this->rowMatchesWhere($row, $where)) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }

        $this->tables[$table] = $kept;

        return $removed;
    }

    public function update(string $table, array $data, array $where): int|false
    {
        if (!isset($this->tables[$table])) {
            return false;
        }

        $updated = 0;

        foreach ($this->tables[$table] as $i => $row) {
            if ($this->rowMatchesWhere($row, $where)) {
                $this->tables[$table][$i] = array_merge($row, $data);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    private function rowMatchesWhere(array $row, array $where): bool
    {
        foreach ($where as $column => $value) {
            if ((string) ($row[$column] ?? null) !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}

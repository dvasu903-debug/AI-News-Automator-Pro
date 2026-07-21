<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Database;

/**
 * Read-only introspection of the module's own tables — existence, engine,
 * and indexes — used by StorageHealthCheck and MigrationRunner's
 * "automatic upgrade detection." Deliberately narrow: this is not a
 * general schema-diffing tool, just enough to answer "is what we expect
 * actually there."
 */
final class SchemaInspector
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function tableExists(string $logicalTable): bool
    {
        global $wpdb;

        $table = $this->connection->table($logicalTable);
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        return $found === $table;
    }

    /**
     * @return list<string> Every expected table that is missing.
     */
    public function missingTables(): array
    {
        $missing = [];

        foreach (Tables::all() as $logical) {
            if (!$this->tableExists($logical)) {
                $missing[] = $logical;
            }
        }

        return $missing;
    }

    public function tableEngine(string $logicalTable): ?string
    {
        global $wpdb;

        $table = $this->connection->table($logicalTable);
        $dbName = $wpdb->dbname;

        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $dbName,
            $table
        ));

        return $engine !== null ? (string) $engine : null;
    }

    /**
     * @return list<string> Index names present on the table.
     */
    public function indexNames(string $logicalTable): array
    {
        global $wpdb;

        if (!$this->tableExists($logicalTable)) {
            return [];
        }

        $table = $this->connection->table($logicalTable);
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A) ?: [];

        $names = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function rowCount(string $logicalTable): int
    {
        if (!$this->tableExists($logicalTable)) {
            return 0;
        }

        return $this->connection->newQuery($logicalTable)->count();
    }
}

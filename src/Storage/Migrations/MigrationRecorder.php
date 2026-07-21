<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Database\SchemaInspector;
use AINewsAutomator\Storage\Database\Tables;

/**
 * Reads/writes `ana_schema_migrations`. Handles the bootstrap case
 * gracefully: before the very first migration runs, that table doesn't
 * exist yet — recordedVersions() returns an empty list rather than
 * erroring, so MigrationRunner correctly treats every migration
 * (including the one that creates this very table) as pending.
 */
final class MigrationRecorder
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SchemaInspector $inspector,
    ) {
    }

    /**
     * @return list<string>
     */
    public function recordedVersions(): array
    {
        if (!$this->inspector->tableExists(Tables::SCHEMA_MIGRATIONS)) {
            return [];
        }

        $rows = $this->connection->newQuery(Tables::SCHEMA_MIGRATIONS)
            ->select(['version'])
            ->get();

        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    public function record(string $version, string $description, int $batch): void
    {
        $this->connection->insert(Tables::SCHEMA_MIGRATIONS, [
            'version'     => $version,
            'description' => $description,
            'batch'       => $batch,
            'applied_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function nextBatchNumber(): int
    {
        if (!$this->inspector->tableExists(Tables::SCHEMA_MIGRATIONS)) {
            return 1;
        }

        $max = $this->connection->scalar(
            'SELECT MAX(batch) FROM `' . $this->connection->table(Tables::SCHEMA_MIGRATIONS) . '`'
        );

        return $max !== null ? ((int) $max) + 1 : 1;
    }
}

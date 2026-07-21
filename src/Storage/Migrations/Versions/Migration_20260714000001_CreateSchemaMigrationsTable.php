<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Bootstrap migration: creates the table that tracks every migration's own
 * history, including this one. MigrationRecorder handles the chicken-and-
 * egg case of this table not existing yet when checking what's pending.
 */
final class Migration_20260714000001_CreateSchemaMigrationsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000001';
    }

    public function description(): string
    {
        return 'Create ana_schema_migrations table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('schema_migrations');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(32) NOT NULL,
  description VARCHAR(255) NOT NULL,
  batch INT UNSIGNED NOT NULL,
  applied_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY version (version)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

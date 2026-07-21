<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260714000006_CreateSourcesTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000006';
    }

    public function description(): string
    {
        return 'Create ana_sources table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('sources');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  type VARCHAR(50) NOT NULL,
  config LONGTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_fetched_at DATETIME DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY type_enabled (type, enabled),
  KEY last_fetched_at (last_fetched_at)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

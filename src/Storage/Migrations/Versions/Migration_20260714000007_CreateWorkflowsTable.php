<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260714000007_CreateWorkflowsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000007';
    }

    public function description(): string
    {
        return 'Create ana_workflows table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('workflows');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  vertical VARCHAR(50) NOT NULL DEFAULT 'news',
  definition LONGTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY vertical_enabled (vertical, enabled)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

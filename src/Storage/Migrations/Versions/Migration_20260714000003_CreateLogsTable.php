<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260714000003_CreateLogsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000003';
    }

    public function description(): string
    {
        return 'Create ana_logs table, replacing OptionBackedLogger storage.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('logs');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level VARCHAR(10) NOT NULL,
  message TEXT NOT NULL,
  context LONGTEXT DEFAULT NULL,
  correlation_id CHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY level_created_at (level, created_at),
  KEY correlation_id (correlation_id),
  KEY created_at (created_at)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

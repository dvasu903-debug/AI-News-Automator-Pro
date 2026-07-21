<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260714000004_CreateAuditTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000004';
    }

    public function description(): string
    {
        return 'Create ana_audit table, replacing OptionBackedAuditRepository storage.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('audit');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  actor_login VARCHAR(60) NOT NULL DEFAULT '',
  action VARCHAR(100) NOT NULL,
  target VARCHAR(191) NOT NULL DEFAULT '',
  correlation_id CHAR(36) DEFAULT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(512) NOT NULL DEFAULT '',
  module VARCHAR(60) NOT NULL DEFAULT '',
  severity VARCHAR(10) NOT NULL DEFAULT 'info',
  result VARCHAR(10) NOT NULL DEFAULT 'success',
  context LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY actor_id_created_at (actor_id, created_at),
  KEY module_severity_created_at (module, severity, created_at),
  KEY correlation_id (correlation_id)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

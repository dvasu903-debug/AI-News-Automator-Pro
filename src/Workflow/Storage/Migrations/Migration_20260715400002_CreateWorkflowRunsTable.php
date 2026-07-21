<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260715400002_CreateWorkflowRunsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260715400002';
    }

    public function description(): string
    {
        return 'Create ana_workflow_runs table (Workflow module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('workflow_runs');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  workflow_key VARCHAR(191) NOT NULL,
  version INT UNSIGNED NOT NULL,
  run_correlation_id CHAR(36) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  triggered_by VARCHAR(30) NOT NULL DEFAULT 'manual',
  user_id BIGINT UNSIGNED DEFAULT NULL,
  current_step_key VARCHAR(191) DEFAULT NULL,
  error TEXT DEFAULT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY workflow_key_status (workflow_key, status),
  KEY correlation_id (run_correlation_id),
  KEY status (status)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

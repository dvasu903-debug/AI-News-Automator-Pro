<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260715400003_CreateWorkflowStepResultsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260715400003';
    }

    public function description(): string
    {
        return 'Create ana_workflow_step_results table (Workflow module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('workflow_step_results');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  step_key VARCHAR(191) NOT NULL,
  action_type VARCHAR(191) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  input LONGTEXT NOT NULL,
  output LONGTEXT NOT NULL,
  error TEXT DEFAULT NULL,
  queue_job_id BIGINT UNSIGNED DEFAULT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  rollback_status VARCHAR(20) DEFAULT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id),
  KEY queue_job_id (queue_job_id),
  KEY status (status)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

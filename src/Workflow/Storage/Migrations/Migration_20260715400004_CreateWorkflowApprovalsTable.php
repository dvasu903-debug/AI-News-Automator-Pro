<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260715400004_CreateWorkflowApprovalsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260715400004';
    }

    public function description(): string
    {
        return 'Create ana_workflow_approvals table (Workflow module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('workflow_approvals');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  step_key VARCHAR(191) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL,
  decided_at DATETIME DEFAULT NULL,
  decided_by BIGINT UNSIGNED DEFAULT NULL,
  reason TEXT DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY run_id_step (run_id, step_key),
  KEY status (status)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

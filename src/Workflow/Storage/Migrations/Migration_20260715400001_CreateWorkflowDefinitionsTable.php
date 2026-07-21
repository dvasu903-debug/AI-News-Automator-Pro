<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Part of Workflow's own migration manifest (ADR-0006: reuses Storage's
 * migration classes without modifying Storage). See
 * MODULE_7_WORKFLOW_ENGINE_DESIGN.md Part 1/3 — Option A: Workflow owns
 * its own immutable, versioned definition table; `ana_workflows`
 * (Storage) is intentionally not used here.
 */
final class Migration_20260715400001_CreateWorkflowDefinitionsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260715400001';
    }

    public function description(): string
    {
        return 'Create ana_workflow_definitions table (Workflow module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('workflow_definitions');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  workflow_key VARCHAR(191) NOT NULL,
  version INT UNSIGNED NOT NULL,
  definition LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY workflow_key_version (workflow_key, version)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

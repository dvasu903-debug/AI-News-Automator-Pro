<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Part of Research's own migration manifest (ADR-0006: reuses Storage's
 * migration classes without modifying Storage).
 */
final class Migration_20260714300005_CreateResearchEntitiesTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714300005';
    }

    public function description(): string
    {
        return 'Create ana_research_entities table (Research module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('research_entities');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  mention_count INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_name_type (session_id, name, entity_type),
  KEY entity_type (entity_type)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

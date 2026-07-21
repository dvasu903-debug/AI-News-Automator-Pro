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
final class Migration_20260714300001_CreateResearchSessionsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714300001';
    }

    public function description(): string
    {
        return 'Create ana_research_sessions table (Research module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('research_sessions');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  correlation_id CHAR(36) NOT NULL,
  topic VARCHAR(255) NOT NULL,
  vertical VARCHAR(50) NOT NULL DEFAULT 'news',
  status VARCHAR(20) NOT NULL DEFAULT 'gathering',
  topic_cluster VARCHAR(191) DEFAULT NULL,
  confidence_score FLOAT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY correlation_id (correlation_id),
  KEY status (status),
  KEY topic_cluster (topic_cluster)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

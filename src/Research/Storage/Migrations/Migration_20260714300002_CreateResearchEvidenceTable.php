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
final class Migration_20260714300002_CreateResearchEvidenceTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714300002';
    }

    public function description(): string
    {
        return 'Create ana_research_evidence table (Research module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('research_evidence');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  source_url TEXT NOT NULL,
  source_type VARCHAR(50) NOT NULL,
  domain VARCHAR(191) NOT NULL,
  credibility_score FLOAT DEFAULT NULL,
  snippet TEXT DEFAULT NULL,
  published_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY domain (domain)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

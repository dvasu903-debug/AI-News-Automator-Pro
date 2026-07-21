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
final class Migration_20260714300006_CreateResearchCitationsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714300006';
    }

    public function description(): string
    {
        return 'Create ana_research_citations table (Research module) — write-once, immutable provenance.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('research_citations');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id BIGINT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NOT NULL,
  citation_text TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY claim_id (claim_id),
  KEY session_lookup (evidence_id)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

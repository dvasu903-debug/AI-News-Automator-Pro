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
final class Migration_20260714300004_CreateResearchClaimEvidenceTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714300004';
    }

    public function description(): string
    {
        return 'Create ana_research_claim_evidence junction table (Research module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('research_claim_evidence');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_id BIGINT UNSIGNED NOT NULL,
  evidence_id BIGINT UNSIGNED NOT NULL,
  relationship VARCHAR(20) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY claim_id (claim_id),
  KEY evidence_id (evidence_id)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

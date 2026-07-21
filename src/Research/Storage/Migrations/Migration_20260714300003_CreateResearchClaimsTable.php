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
final class Migration_20260714300003_CreateResearchClaimsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714300003';
    }

    public function description(): string
    {
        return 'Create ana_research_claims table (Research module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('research_claims');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  statement TEXT NOT NULL,
  confidence_score FLOAT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'unverified',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY status (status)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

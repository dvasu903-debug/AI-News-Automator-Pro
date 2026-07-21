<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Sources-owned migration (ADR-0006). Minimal schema per the approved
 * decision: source_id, fingerprint, first_seen, last_seen, status — no
 * duplicate article/item content storage.
 */
final class Migration_20260714200001_CreateSourceItemsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714200001';
    }

    public function description(): string
    {
        return 'Create ana_source_items table (Sources module — deduplication fingerprints).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('source_items');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id BIGINT UNSIGNED NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'seen',
  PRIMARY KEY  (id),
  UNIQUE KEY source_fingerprint (source_id, fingerprint),
  KEY last_seen (last_seen)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

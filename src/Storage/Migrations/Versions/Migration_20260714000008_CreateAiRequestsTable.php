<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260714000008_CreateAiRequestsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000008';
    }

    public function description(): string
    {
        return 'Create ana_ai_requests table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('ai_requests');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(50) NOT NULL,
  model VARCHAR(100) NOT NULL,
  purpose VARCHAR(50) NOT NULL,
  correlation_id CHAR(36) DEFAULT NULL,
  prompt_tokens INT UNSIGNED DEFAULT NULL,
  completion_tokens INT UNSIGNED DEFAULT NULL,
  cost_cents INT UNSIGNED DEFAULT NULL,
  status VARCHAR(20) NOT NULL,
  error TEXT DEFAULT NULL,
  duration_ms INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY provider_created_at (provider, created_at),
  KEY correlation_id (correlation_id),
  KEY purpose_created_at (purpose, created_at)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

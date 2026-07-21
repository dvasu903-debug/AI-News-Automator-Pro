<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Links a correlation_id (an AI request already recorded in Storage's
 * frozen `ana_ai_requests`) to the prompt template/version that produced
 * it — a separate table rather than an ALTER TABLE on a frozen Storage
 * table, joined by correlation_id when reporting needs both.
 */
final class Migration_20260714100002_CreatePromptHistoryTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714100002';
    }

    public function description(): string
    {
        return 'Create ana_prompt_history table (AI module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('prompt_history');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  correlation_id CHAR(36) NOT NULL,
  prompt_template_id BIGINT UNSIGNED NOT NULL,
  template_version VARCHAR(20) NOT NULL,
  rendered_variables_hash CHAR(32) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY correlation_id (correlation_id),
  KEY template_id_version (prompt_template_id, template_version)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

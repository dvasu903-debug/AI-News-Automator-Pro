<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * AI-module-owned migration, using Storage's reusable (not modified)
 * AbstractMigration/SchemaBuilder classes — see module README, "Storage
 * is frozen from modification, not from reuse."
 */
final class Migration_20260714100001_CreatePromptTemplatesTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714100001';
    }

    public function description(): string
    {
        return 'Create ana_prompt_templates table (AI module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('prompt_templates');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  version VARCHAR(20) NOT NULL,
  vertical VARCHAR(50) NOT NULL DEFAULT 'news',
  template_text LONGTEXT NOT NULL,
  variables_schema LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY name_version (name, version),
  KEY vertical (vertical)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

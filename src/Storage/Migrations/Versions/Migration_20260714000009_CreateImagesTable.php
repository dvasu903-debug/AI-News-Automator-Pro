<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

final class Migration_20260714000009_CreateImagesTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000009';
    }

    public function description(): string
    {
        return 'Create ana_images table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('images');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attachment_id BIGINT UNSIGNED DEFAULT NULL,
  article_id BIGINT UNSIGNED DEFAULT NULL,
  source VARCHAR(30) NOT NULL,
  source_url TEXT DEFAULT NULL,
  credit_text VARCHAR(255) DEFAULT NULL,
  credit_url TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY article_id (article_id),
  KEY attachment_id (attachment_id)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

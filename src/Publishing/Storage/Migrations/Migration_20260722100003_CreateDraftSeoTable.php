<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Structured SEO metadata not naturally covered by wp_postmeta's flat
 * key/value shape. Kept as its own table (rather than several postmeta
 * rows) specifically so a future SEO module can own and extend it
 * without needing to touch Publishing. post_id referenced by convention
 * only — no DB foreign key (ADR-0004); wp_posts is WordPress core's.
 */
final class Migration_20260722100003_CreateDraftSeoTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260722100003';
    }

    public function description(): string
    {
        return 'Create ana_draft_seo table (Publishing module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('draft_seo');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  meta_title VARCHAR(255) NULL,
  meta_description VARCHAR(500) NULL,
  focus_keyword VARCHAR(191) NULL,
  canonical_url VARCHAR(2048) NULL,
  robots_directives VARCHAR(191) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY post_id (post_id)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Milestone 2 authorized schema addition — see docs/verification/
 * authorized-frozen-changes.txt (to be recorded there at freeze). This
 * migration is NEW; it does not modify the frozen
 * Migration_20260722100001_CreatePublishingProfilesTable.
 *
 * Adds the default-profile flag the Milestone 2 service layer requires
 * (findDefault()/markDefault(), policies P1-P4). The default-profile
 * concept was approved to be retained rather than redesigned out of
 * Milestone 2 (see PublishingProfileService), so the column is added
 * here via a new version instead of touching frozen SQL.
 *
 * dbDelta() is diff-based: passing the full intended CREATE TABLE for an
 * already-existing table causes it to ALTER TABLE in place for any
 * missing column/index, leaving existing data and the existing UNIQUE
 * KEY slug untouched. This is the same mechanism SchemaBuilder::run()
 * uses for table creation, applied here for an additive column change.
 */
final class Migration_20260722100004_AddIsDefaultToPublishingProfilesTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260722100004';
    }

    public function description(): string
    {
        return 'Add is_default column to ana_publishing_profiles (Publishing module, Milestone 2).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('publishing_profiles');
        $charsetCollate = SchemaBuilder::charsetCollate();

        // Full intended schema, matching Migration_20260722100001 exactly
        // plus the new column and index — dbDelta diffs this against the
        // live table and only adds what's missing.
        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(191) NOT NULL,
  name VARCHAR(191) NOT NULL,
  vertical VARCHAR(50) NOT NULL DEFAULT 'news',
  workflow_key VARCHAR(191) NOT NULL,
  approval_mode VARCHAR(30) NOT NULL DEFAULT 'manual',
  config LONGTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY vertical_enabled (vertical, enabled),
  KEY is_default (is_default)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Part of Publishing's own migration manifest (ADR-0006: reuses
 * Storage's migration classes without modifying Storage). A profile is
 * a reusable pipeline configuration — data, not code — referencing a
 * Module 7 workflow_key by convention only (no DB foreign key, per
 * ADR-0004). See MODULE_8_PUBLISHING_ENGINE_DESIGN.md §3/§5.
 */
final class Migration_20260722100001_CreatePublishingProfilesTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260722100001';
    }

    public function description(): string
    {
        return 'Create ana_publishing_profiles table (Publishing module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('publishing_profiles');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(191) NOT NULL,
  name VARCHAR(191) NOT NULL,
  vertical VARCHAR(50) NOT NULL DEFAULT 'news',
  workflow_key VARCHAR(191) NOT NULL,
  approval_mode VARCHAR(30) NOT NULL DEFAULT 'manual',
  config LONGTEXT NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY vertical_enabled (vertical, enabled)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Joins a WP_Post (draft/article) to the Module 7 workflow run driving
 * its publication. post_id and workflow_run_id are referenced by
 * convention only — no DB foreign key (ADR-0004); wp_posts and
 * wp_ana_workflow_runs are owned by WordPress core and Module 7
 * respectively, neither of which this migration may touch.
 */
final class Migration_20260722100002_CreatePublishingRunsTable extends AbstractMigration
{
    public function version(): string
    {
        return '20260722100002';
    }

    public function description(): string
    {
        return 'Create ana_publishing_runs table (Publishing module).';
    }

    public function up(ConnectionInterface $connection): void
    {
        $table = SchemaBuilder::tableName('publishing_runs');
        $charsetCollate = SchemaBuilder::charsetCollate();

        $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  profile_id BIGINT UNSIGNED NOT NULL,
  workflow_run_id BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY workflow_run_id (workflow_run_id),
  KEY status (status)
) {$charsetCollate};";

        SchemaBuilder::run([$sql]);
    }
}

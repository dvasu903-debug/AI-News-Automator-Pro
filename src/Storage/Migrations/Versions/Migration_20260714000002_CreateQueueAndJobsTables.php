<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Creates ana_queue (active jobs, kept small) and ana_jobs (completed/
 * failed/cancelled history, retention-pruned) — the queue/history split
 * from the approved Storage design.
 */
final class Migration_20260714000002_CreateQueueAndJobsTables extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000002';
    }

    public function description(): string
    {
        return 'Create ana_queue and ana_jobs tables.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $charsetCollate = SchemaBuilder::charsetCollate();
        $queueTable = SchemaBuilder::tableName('queue');
        $jobsTable = SchemaBuilder::tableName('jobs');

        $queueSql = "CREATE TABLE {$queueTable} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(191) NOT NULL,
  status VARCHAR(20) NOT NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  worker VARCHAR(64) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  result LONGTEXT DEFAULT NULL,
  error TEXT DEFAULT NULL,
  correlation_id CHAR(36) DEFAULT NULL,
  run_after DATETIME DEFAULT NULL,
  locked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  started_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_run_after_priority (status, run_after, priority),
  KEY correlation_id (correlation_id),
  KEY worker (worker)
) {$charsetCollate};";

        $jobsSql = "CREATE TABLE {$jobsTable} (
  id BIGINT UNSIGNED NOT NULL,
  job_type VARCHAR(191) NOT NULL,
  status VARCHAR(20) NOT NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  worker VARCHAR(64) DEFAULT NULL,
  payload LONGTEXT DEFAULT NULL,
  result LONGTEXT DEFAULT NULL,
  error TEXT DEFAULT NULL,
  correlation_id CHAR(36) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  started_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY job_type_status_finished_at (job_type, status, finished_at),
  KEY correlation_id (correlation_id),
  KEY finished_at (finished_at)
) {$charsetCollate};";

        SchemaBuilder::run([$queueSql, $jobsSql]);
    }
}

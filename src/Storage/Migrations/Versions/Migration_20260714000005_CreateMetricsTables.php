<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations\Versions;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Migrations\AbstractMigration;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;

/**
 * Creates ana_metrics (discrete time-series events) and
 * ana_metric_counters (atomic running totals via INSERT ... ON DUPLICATE
 * KEY UPDATE, replacing SecurityMetrics' read-modify-write race).
 */
final class Migration_20260714000005_CreateMetricsTables extends AbstractMigration
{
    public function version(): string
    {
        return '20260714000005';
    }

    public function description(): string
    {
        return 'Create ana_metrics and ana_metric_counters tables.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $charsetCollate = SchemaBuilder::charsetCollate();
        $metricsTable = SchemaBuilder::tableName('metrics');
        $countersTable = SchemaBuilder::tableName('metric_counters');

        $metricsSql = "CREATE TABLE {$metricsTable} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric_key VARCHAR(100) NOT NULL,
  value BIGINT NOT NULL DEFAULT 0,
  dimensions LONGTEXT DEFAULT NULL,
  bucket_hour DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY metric_key_bucket_hour (metric_key, bucket_hour),
  KEY created_at (created_at)
) {$charsetCollate};";

        $countersSql = "CREATE TABLE {$countersTable} (
  metric_key VARCHAR(100) NOT NULL,
  dimension_hash CHAR(32) NOT NULL,
  dimensions LONGTEXT DEFAULT NULL,
  value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (metric_key, dimension_hash)
) {$charsetCollate};";

        SchemaBuilder::run([$metricsSql, $countersSql]);
    }
}

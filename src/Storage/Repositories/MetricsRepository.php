<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Database\BatchPurger;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\MetricEvent;
use AINewsAutomator\Storage\Query\Filter;

/**
 * Backs both `ana_metric_counters` (atomic running totals, fixing the
 * read-modify-write race the old option-backed SecurityMetrics had) and
 * `ana_metrics` (discrete time-series events for aggregation). Not an
 * AbstractRepository subclass — counters have a composite (metric_key,
 * dimension_hash) key rather than an auto-increment id, so the standard
 * findRow/insertRow/paginateRows scaffolding doesn't fit; this repository
 * is simple enough not to need it.
 *
 * Implements PurgeableInterface for the `ana_metrics` EVENT table only —
 * `ana_metric_counters` (running totals) are never purged by age, since
 * deleting a counter would silently reset a total.
 */
final class MetricsRepository implements MetricsRepositoryInterface, PurgeableInterface
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function increment(string $metricKey, int $by = 1, array $dimensions = []): void
    {
        $hash = $this->dimensionHash($dimensions);
        $now = EntityDates::toMysql(EntityDates::now());

        $this->connection->upsertIncrement(
            Tables::METRIC_COUNTERS,
            [
                'metric_key'     => $metricKey,
                'dimension_hash' => $hash,
                'dimensions'     => wp_json_encode($dimensions) ?: '{}',
                'value'          => $by,
                'updated_at'     => $now,
            ],
            ['value' => $by]
        );
    }

    public function counterValue(string $metricKey, array $dimensions = []): int
    {
        $hash = $this->dimensionHash($dimensions);

        $row = $this->connection->newQuery(Tables::METRIC_COUNTERS)
            ->whereAll([Filter::equals('metric_key', $metricKey), Filter::equals('dimension_hash', $hash)])
            ->first();

        return $row !== null ? (int) $row['value'] : 0;
    }

    public function allCounters(): array
    {
        $rows = $this->connection->newQuery(Tables::METRIC_COUNTERS)->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $row['metric_key'] . ':' . $row['dimension_hash'];
            $result[$key] = (int) $row['value'];
        }

        return $result;
    }

    public function record(string $metricKey, int $value, array $dimensions = []): void
    {
        $now = EntityDates::now();
        $bucketHour = $now->setTime((int) $now->format('H'), 0, 0);

        $event = new MetricEvent(
            id: null,
            metricKey: $metricKey,
            value: $value,
            dimensions: $dimensions,
            bucketHour: $bucketHour,
            createdAt: $now,
        );

        $this->connection->insert(Tables::METRICS, $event->toRow());
    }

    public function aggregateHourly(string $metricKey, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $table = $this->connection->table(Tables::METRICS);

        $rows = $this->connection->select(
            "SELECT bucket_hour, SUM(value) AS total FROM `{$table}` WHERE metric_key = %s AND bucket_hour BETWEEN %s AND %s GROUP BY bucket_hour ORDER BY bucket_hour ASC",
            [$metricKey, EntityDates::toMysql($from), EntityDates::toMysql($to)]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['bucket_hour']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $dimensions
     */
    private function dimensionHash(array $dimensions): string
    {
        ksort($dimensions);
        return md5(wp_json_encode($dimensions) ?: '{}');
    }

    public function purgeOlderThan(int $days): int
    {
        return BatchPurger::purgeOlderThan($this->connection, Tables::METRICS, 'created_at', $days);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A discrete row in `ana_metrics` — one measured event (e.g. one AI call's
 * cost), for time-series aggregation. Distinct from the atomic running
 * totals in `ana_metric_counters` (see MetricsRepositoryInterface).
 */
final class MetricEvent
{
    /**
     * @param array<string, mixed> $dimensions
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $metricKey,
        public readonly int $value,
        public readonly array $dimensions,
        public readonly \DateTimeImmutable $bucketHour,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            metricKey: (string) $row['metric_key'],
            value: (int) $row['value'],
            dimensions: is_string($row['dimensions'] ?? null) ? (json_decode($row['dimensions'], true) ?: []) : [],
            bucketHour: EntityDates::fromMysql((string) $row['bucket_hour']),
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'metric_key'  => $this->metricKey,
            'value'       => $this->value,
            'dimensions'  => wp_json_encode($this->dimensions) ?: '{}',
            'bucket_hour' => EntityDates::toMysql($this->bucketHour),
            'created_at'  => EntityDates::toMysql($this->createdAt),
        ];
    }
}

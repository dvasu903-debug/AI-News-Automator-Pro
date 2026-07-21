<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in the active `ana_queue` table (pending/processing/delayed jobs
 * only — completed/failed/cancelled jobs move to JobHistoryEntry, see
 * module README §Queue/History split).
 */
final class QueueJob
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $jobType,
        public readonly JobStatus $status,
        public readonly int $priority,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $worker,
        public readonly array $payload,
        public readonly ?string $correlationId,
        public readonly ?\DateTimeImmutable $runAfter,
        public readonly ?\DateTimeImmutable $lockedAt,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $startedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            jobType: (string) $row['job_type'],
            status: JobStatus::from((string) $row['status']),
            priority: (int) $row['priority'],
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            worker: $row['worker'] !== null ? (string) $row['worker'] : null,
            payload: is_string($row['payload'] ?? null) ? (json_decode($row['payload'], true) ?: []) : [],
            correlationId: $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
            runAfter: EntityDates::nullableFromMysql($row['run_after'] ?? null),
            lockedAt: EntityDates::nullableFromMysql($row['locked_at'] ?? null),
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
            startedAt: EntityDates::nullableFromMysql($row['started_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'job_type'       => $this->jobType,
            'status'         => $this->status->value,
            'priority'       => $this->priority,
            'attempts'       => $this->attempts,
            'max_attempts'   => $this->maxAttempts,
            'worker'         => $this->worker,
            'payload'        => wp_json_encode($this->payload) ?: '{}',
            'correlation_id' => $this->correlationId,
            'run_after'      => EntityDates::nullableToMysql($this->runAfter),
            'locked_at'      => EntityDates::nullableToMysql($this->lockedAt),
            'created_at'     => EntityDates::toMysql($this->createdAt),
            'started_at'     => EntityDates::nullableToMysql($this->startedAt),
        ];
    }
}

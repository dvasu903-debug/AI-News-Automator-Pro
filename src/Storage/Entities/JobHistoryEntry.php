<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in `ana_jobs` — a completed/failed/cancelled job, moved here from
 * `ana_queue` on lifecycle completion. Reuses the originating queue row's
 * id as its own primary key (stable job identity across its lifecycle).
 */
final class JobHistoryEntry
{
    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $jobType,
        public readonly JobStatus $status,
        public readonly int $priority,
        public readonly int $attempts,
        public readonly ?string $worker,
        public readonly ?array $payload,
        public readonly ?array $result,
        public readonly ?string $error,
        public readonly ?string $correlationId,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $finishedAt,
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
            worker: $row['worker'] !== null ? (string) $row['worker'] : null,
            payload: is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null,
            result: is_string($row['result'] ?? null) ? json_decode($row['result'], true) : null,
            error: $row['error'] !== null ? (string) $row['error'] : null,
            correlationId: $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
            startedAt: EntityDates::nullableFromMysql($row['started_at'] ?? null),
            finishedAt: EntityDates::nullableFromMysql($row['finished_at'] ?? null),
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
            'worker'         => $this->worker,
            'payload'        => $this->payload !== null ? (wp_json_encode($this->payload) ?: null) : null,
            'result'         => $this->result !== null ? (wp_json_encode($this->result) ?: null) : null,
            'error'          => $this->error,
            'correlation_id' => $this->correlationId,
            'created_at'     => EntityDates::toMysql($this->createdAt),
            'started_at'     => EntityDates::nullableToMysql($this->startedAt),
            'finished_at'    => EntityDates::nullableToMysql($this->finishedAt),
        ];
    }

    public static function fromQueueJob(QueueJob $job, JobStatus $finalStatus, ?array $result, ?string $error): self
    {
        return new self(
            id: $job->id,
            jobType: $job->jobType,
            status: $finalStatus,
            priority: $job->priority,
            attempts: $job->attempts,
            worker: $job->worker,
            payload: $job->payload,
            result: $result,
            error: $error,
            correlationId: $job->correlationId,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            finishedAt: EntityDates::now(),
        );
    }
}

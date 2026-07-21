<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in `ana_logs`. Mirrors the shape Core's LoggerInterface already
 * works with (level, interpolated message, context, correlation id) —
 * this is the persistence-layer counterpart TableBackedLogger maps to.
 */
final class LogEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $level,
        public readonly string $message,
        public readonly array $context,
        public readonly ?string $correlationId,
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
            level: (string) $row['level'],
            message: (string) $row['message'],
            context: is_string($row['context'] ?? null) ? (json_decode($row['context'], true) ?: []) : [],
            correlationId: $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'level'          => $this->level,
            'message'        => $this->message,
            'context'        => wp_json_encode($this->context) ?: '{}',
            'correlation_id' => $this->correlationId,
            'created_at'     => EntityDates::toMysql($this->createdAt),
        ];
    }
}

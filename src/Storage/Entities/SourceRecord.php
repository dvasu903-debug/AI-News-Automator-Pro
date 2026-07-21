<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in `ana_sources` — one configured trend/content source (RSS feed,
 * NewsAPI query, etc.) for the Sources module (5) to poll.
 */
final class SourceRecord
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly array $config,
        public readonly bool $enabled,
        public readonly ?\DateTimeImmutable $lastFetchedAt,
        public readonly ?string $lastError,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: (string) $row['name'],
            type: (string) $row['type'],
            config: is_string($row['config'] ?? null) ? (json_decode($row['config'], true) ?: []) : [],
            enabled: (bool) $row['enabled'],
            lastFetchedAt: EntityDates::nullableFromMysql($row['last_fetched_at'] ?? null),
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
            updatedAt: EntityDates::fromMysql((string) $row['updated_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'name'            => $this->name,
            'type'            => $this->type,
            'config'          => wp_json_encode($this->config) ?: '{}',
            'enabled'         => $this->enabled ? 1 : 0,
            'last_fetched_at' => EntityDates::nullableToMysql($this->lastFetchedAt),
            'last_error'      => $this->lastError,
            'created_at'      => EntityDates::toMysql($this->createdAt),
            'updated_at'      => EntityDates::toMysql($this->updatedAt),
        ];
    }
}

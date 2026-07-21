<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in `ana_workflows` — a configured pipeline definition for the
 * Pipeline module (8). `vertical` ties directly into the engine/vertical
 * distinction established in NAMING.md (defaults to "news").
 */
final class WorkflowRecord
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $vertical,
        public readonly array $definition,
        public readonly bool $enabled,
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
            vertical: (string) $row['vertical'],
            definition: is_string($row['definition'] ?? null) ? (json_decode($row['definition'], true) ?: []) : [],
            enabled: (bool) $row['enabled'],
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
            'name'       => $this->name,
            'vertical'   => $this->vertical,
            'definition' => wp_json_encode($this->definition) ?: '{}',
            'enabled'    => $this->enabled ? 1 : 0,
            'created_at' => EntityDates::toMysql($this->createdAt),
            'updated_at' => EntityDates::toMysql($this->updatedAt),
        ];
    }
}

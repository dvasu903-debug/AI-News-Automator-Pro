<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * A named entity (person/organization/place/event) extracted from
 * Evidence. Named ExtractedEntity, not Entity, to avoid confusion with
 * Storage's own "Entities" directory convention and with the generic
 * word "entity" used loosely elsewhere in the codebase's documentation.
 */
final class ExtractedEntity
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $sessionId,
        public readonly string $name,
        public readonly string $entityType,
        public readonly int $mentionCount,
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
            sessionId: (int) $row['session_id'],
            name: (string) $row['name'],
            entityType: (string) $row['entity_type'],
            mentionCount: (int) $row['mention_count'],
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'session_id'    => $this->sessionId,
            'name'          => $this->name,
            'entity_type'   => $this->entityType,
            'mention_count' => $this->mentionCount,
            'created_at'    => EntityDates::toMysql($this->createdAt),
        ];
    }

    public function withIncrementedMentionCount(): self
    {
        return new self($this->id, $this->sessionId, $this->name, $this->entityType, $this->mentionCount + 1, $this->createdAt);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Dedup;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * A row in ana_source_items — the minimal fingerprint record: which
 * source, which item (by hash), when first/last seen, and its status.
 * Deliberately does NOT store the item's title/url/content — this is a
 * dedup index, not a duplicate copy of article data (explicit approved
 * requirement: "No duplicate article storage").
 */
final class SourceItemFingerprint
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $sourceId,
        public readonly string $fingerprint,
        public readonly \DateTimeImmutable $firstSeen,
        public readonly \DateTimeImmutable $lastSeen,
        public readonly SourceItemStatus $status,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            sourceId: (int) $row['source_id'],
            fingerprint: (string) $row['fingerprint'],
            firstSeen: EntityDates::fromMysql((string) $row['first_seen']),
            lastSeen: EntityDates::fromMysql((string) $row['last_seen']),
            status: SourceItemStatus::from((string) $row['status']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'source_id'  => $this->sourceId,
            'fingerprint' => $this->fingerprint,
            'first_seen' => EntityDates::toMysql($this->firstSeen),
            'last_seen'  => EntityDates::toMysql($this->lastSeen),
            'status'     => $this->status->value,
        ];
    }

    public function withLastSeen(\DateTimeImmutable $lastSeen, SourceItemStatus $status): self
    {
        return new self($this->id, $this->sourceId, $this->fingerprint, $this->firstSeen, $lastSeen, $status);
    }
}

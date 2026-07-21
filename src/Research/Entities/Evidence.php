<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * One piece of source material considered during research. Immutable
 * once recorded — no update path is exposed anywhere in this module
 * (part of the "immutable provenance" requirement). If a source's
 * content changes, a NEW Evidence record is created; the old one is
 * never edited in place.
 */
final class Evidence
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $sessionId,
        public readonly string $sourceUrl,
        public readonly string $sourceType,
        public readonly string $domain,
        public readonly ?float $credibilityScore,
        public readonly ?string $snippet,
        public readonly ?\DateTimeImmutable $publishedAt,
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
            sourceUrl: (string) $row['source_url'],
            sourceType: (string) $row['source_type'],
            domain: (string) $row['domain'],
            credibilityScore: isset($row['credibility_score']) && $row['credibility_score'] !== null ? (float) $row['credibility_score'] : null,
            snippet: $row['snippet'] !== null ? (string) $row['snippet'] : null,
            publishedAt: EntityDates::nullableFromMysql($row['published_at'] ?? null),
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'session_id'        => $this->sessionId,
            'source_url'        => $this->sourceUrl,
            'source_type'       => $this->sourceType,
            'domain'            => $this->domain,
            'credibility_score' => $this->credibilityScore,
            'snippet'           => $this->snippet,
            'published_at'      => EntityDates::nullableToMysql($this->publishedAt),
            'created_at'        => EntityDates::toMysql($this->createdAt),
        ];
    }
}

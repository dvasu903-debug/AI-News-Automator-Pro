<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * An immutable, write-once formatted reference tying a Claim to its
 * Evidence — the same discipline as AI's PromptTemplate versioning
 * (CitationRepository exposes no update path; a correction creates a
 * new Citation, the old one is never edited in place).
 */
final class Citation
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $claimId,
        public readonly int $evidenceId,
        public readonly string $citationText,
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
            claimId: (int) $row['claim_id'],
            evidenceId: (int) $row['evidence_id'],
            citationText: (string) $row['citation_text'],
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'claim_id'      => $this->claimId,
            'evidence_id'   => $this->evidenceId,
            'citation_text' => $this->citationText,
            'created_at'    => EntityDates::toMysql($this->createdAt),
        ];
    }
}

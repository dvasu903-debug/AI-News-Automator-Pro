<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * Junction record: one Claim's relationship (supports/contradicts) to
 * one piece of Evidence. Many-to-many — one claim can have multiple
 * supporting/contradicting evidence, and one piece of evidence can bear
 * on multiple claims.
 */
final class ClaimEvidenceLink
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $claimId,
        public readonly int $evidenceId,
        public readonly EvidenceRelationship $relationship,
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
            relationship: EvidenceRelationship::from((string) $row['relationship']),
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'claim_id'     => $this->claimId,
            'evidence_id'  => $this->evidenceId,
            'relationship' => $this->relationship->value,
            'created_at'   => EntityDates::toMysql($this->createdAt),
        ];
    }
}

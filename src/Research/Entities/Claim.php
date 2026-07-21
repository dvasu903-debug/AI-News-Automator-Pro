<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * One extracted factual assertion. confidence_score and status are the
 * only mutable fields (updated as evidence/contradictions accumulate) —
 * the statement text itself, once extracted, is not edited.
 */
final class Claim
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $sessionId,
        public readonly string $statement,
        public readonly float $confidenceScore,
        public readonly ClaimStatus $status,
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
            statement: (string) $row['statement'],
            confidenceScore: (float) $row['confidence_score'],
            status: ClaimStatus::from((string) $row['status']),
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'session_id'       => $this->sessionId,
            'statement'        => $this->statement,
            'confidence_score' => $this->confidenceScore,
            'status'           => $this->status->value,
            'created_at'       => EntityDates::toMysql($this->createdAt),
        ];
    }

    public function withStatus(ClaimStatus $status): self
    {
        return new self($this->id, $this->sessionId, $this->statement, $this->confidenceScore, $status, $this->createdAt);
    }

    public function withConfidenceScore(float $score): self
    {
        return new self($this->id, $this->sessionId, $this->statement, $score, $this->status, $this->createdAt);
    }
}

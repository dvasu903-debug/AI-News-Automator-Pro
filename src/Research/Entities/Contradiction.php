<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Entities;

use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * A flagged conflict between two Claims within the same session.
 * resolved defaults to false; ContradictionRepository exposes a
 * resolve() operation (an explicit editorial/researcher action), never
 * an automatic one — the module does not decide on its own that a
 * contradiction has gone away.
 */
final class Contradiction
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $sessionId,
        public readonly int $claimAId,
        public readonly int $claimBId,
        public readonly string $description,
        public readonly ContradictionSeverity $severity,
        public readonly bool $resolved,
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
            claimAId: (int) $row['claim_a_id'],
            claimBId: (int) $row['claim_b_id'],
            description: (string) $row['description'],
            severity: ContradictionSeverity::from((string) $row['severity']),
            resolved: (bool) $row['resolved'],
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'session_id'  => $this->sessionId,
            'claim_a_id'  => $this->claimAId,
            'claim_b_id'  => $this->claimBId,
            'description' => $this->description,
            'severity'    => $this->severity->value,
            'resolved'    => $this->resolved ? 1 : 0,
            'created_at'  => EntityDates::toMysql($this->createdAt),
        ];
    }

    public function withResolved(bool $resolved): self
    {
        return new self($this->id, $this->sessionId, $this->claimAId, $this->claimBId, $this->description, $this->severity, $resolved, $this->createdAt);
    }
}

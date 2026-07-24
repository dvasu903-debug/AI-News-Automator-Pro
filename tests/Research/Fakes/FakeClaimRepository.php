<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\ClaimRepositoryInterface;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimEvidenceLink;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\EvidenceRelationship;
use AINewsAutomator\Storage\Entities\EntityDates;

final class FakeClaimRepository implements ClaimRepositoryInterface
{
    /** @var array<int, Claim> */
    public array $rows = [];
    /** @var list<ClaimEvidenceLink> */
    public array $links = [];
    private int $nextId = 1;
    private int $nextLinkId = 1;

    public function record(Claim $claim): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = new Claim($id, $claim->sessionId, $claim->statement, $claim->confidenceScore, $claim->status, $claim->createdAt);
        return $id;
    }

    public function updateStatusAndConfidence(int $claimId, ClaimStatus $status, float $confidence): void
    {
        $claim = $this->rows[$claimId];
        $this->rows[$claimId] = new Claim($claim->id, $claim->sessionId, $claim->statement, $confidence, $status, $claim->createdAt);
    }

    public function forSession(int $sessionId): array
    {
        return array_values(array_filter($this->rows, static fn (Claim $c): bool => $c->sessionId === $sessionId));
    }

    public function find(int $id): ?Claim
    {
        return $this->rows[$id] ?? null;
    }

    public function linkEvidence(int $claimId, int $evidenceId, EvidenceRelationship $relationship): int
    {
        $id = $this->nextLinkId++;
        $this->links[] = new ClaimEvidenceLink($id, $claimId, $evidenceId, $relationship, EntityDates::now());
        return $id;
    }

    public function evidenceLinksFor(int $claimId): array
    {
        return array_values(array_filter($this->links, static fn (ClaimEvidenceLink $l): bool => $l->claimId === $claimId));
    }
}

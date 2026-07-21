<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimEvidenceLink;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\EvidenceRelationship;

/**
 * Persists extracted claims and their evidentiary links
 * (supports/contradicts) to Evidence.
 */
interface ClaimRepositoryInterface
{
    public function record(Claim $claim): int;

    public function updateStatusAndConfidence(int $claimId, ClaimStatus $status, float $confidence): void;

    /**
     * @return list<Claim>
     */
    public function forSession(int $sessionId): array;

    public function find(int $id): ?Claim;

    public function linkEvidence(int $claimId, int $evidenceId, EvidenceRelationship $relationship): int;

    /**
     * @return list<ClaimEvidenceLink>
     */
    public function evidenceLinksFor(int $claimId): array;
}

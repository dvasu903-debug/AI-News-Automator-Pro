<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimEvidenceLink;

/**
 * Scores a claim's or a session's confidence. See CompositeConfidenceScorer
 * for why this is deliberately deterministic, not an AI call per claim.
 */
interface ResearchConfidenceInterface
{
    /**
     * @param list<ClaimEvidenceLink> $links
     */
    public function scoreClaim(Claim $claim, array $links): float;

    /**
     * @param list<Claim> $claims
     */
    public function scoreSession(array $claims): float;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\Contradiction;

/**
 * Detects contradictions between a newly extracted claim and a
 * session's existing claims. Detection only — never persists.
 */
interface ContradictionDetectorInterface
{
    /**
     * Compares ONE new claim against existing claims in the same
     * session — O(n) per new claim, not an O(n^2) full re-scan on every
     * call (see module README for the reasoning).
     *
     * @param list<Claim> $existingClaims
     * @return list<Contradiction>
     */
    public function detectFor(Claim $newClaim, array $existingClaims): array;
}

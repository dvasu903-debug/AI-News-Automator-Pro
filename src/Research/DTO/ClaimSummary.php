<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\DTO;

use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Research\Entities\Claim;

/**
 * A Claim bundled with its Citations — Publishing (Module 8, future)
 * wants these together, not as two flat lists requiring a manual join
 * against claim_id every time.
 */
final class ClaimSummary
{
    /**
     * @param list<Citation> $citations
     */
    public function __construct(
        public readonly Claim $claim,
        public readonly array $citations,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\DTO\ExtractedClaimData;
use AINewsAutomator\Research\Entities\Evidence;

/**
 * Extracts discrete, checkable factual claim statements from one piece
 * of Evidence. Detection only — never persists (ResearchSessionManager does).
 */
interface ClaimExtractorInterface
{
    /**
     * @return list<ExtractedClaimData>
     */
    public function extract(Evidence $evidence): array;
}

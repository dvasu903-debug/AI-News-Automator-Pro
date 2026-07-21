<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\DTO;

/**
 * Source diversity analysis for a session's evidence set — an input to
 * confidence scoring and a standalone health/reporting signal.
 */
final class DiversityReport
{
    public function __construct(
        public readonly int $totalEvidence,
        public readonly int $distinctDomains,
        public readonly int $distinctSourceTypes,
        public readonly float $diversityScore,
    ) {
    }
}

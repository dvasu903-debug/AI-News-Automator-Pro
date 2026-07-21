<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Diversity;

use AINewsAutomator\Research\Contracts\SourceDiversityAnalyzerInterface;
use AINewsAutomator\Research\DTO\DiversityReport;

/**
 * Deterministic, no AI call — a simple function of the evidence set
 * already gathered. Diversity score is distinct-domain-ratio weighted
 * against a soft cap (more than ~5 distinct domains doesn't meaningfully
 * increase confidence further; a single-domain session scores lowest).
 */
final class SourceDiversityAnalyzer implements SourceDiversityAnalyzerInterface
{
    private const DIVERSITY_SOFT_CAP = 5;

    public function analyze(array $evidence): DiversityReport
    {
        if ($evidence === []) {
            return new DiversityReport(0, 0, 0, 0.0);
        }

        $domains = [];
        $sourceTypes = [];

        foreach ($evidence as $item) {
            $domains[$item->domain] = true;
            $sourceTypes[$item->sourceType] = true;
        }

        $distinctDomains = count($domains);
        $diversityScore = min(1.0, $distinctDomains / self::DIVERSITY_SOFT_CAP);

        return new DiversityReport(
            totalEvidence: count($evidence),
            distinctDomains: $distinctDomains,
            distinctSourceTypes: count($sourceTypes),
            diversityScore: round($diversityScore, 4),
        );
    }
}

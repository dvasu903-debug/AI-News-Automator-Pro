<?php
/**
 * Builds minimal-but-valid ResearchSummary fixtures for Publishing
 * Milestone 4 tests — every field Publishing's new code actually reads
 * (claims/citations, overallConfidence, unresolvedContradictions) is
 * configurable; everything else is a fixed, valid placeholder.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Research\DTO\ClaimSummary;
use AINewsAutomator\Research\DTO\DiversityReport;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\Contradiction;
use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Storage\Entities\EntityDates;

final class ResearchSummaryFixture
{
    /**
     * @param list<array{statement: string, citationTexts: list<string>}> $claims
     * @param list<ContradictionSeverity> $contradictionSeverities
     */
    public static function build(
        int $sessionId = 1,
        array $claims = [['statement' => 'The sky is blue.', 'citationTexts' => ['Example Source, 2026.']]],
        float $overallConfidence = 0.9,
        array $contradictionSeverities = [],
    ): ResearchSummary {
        $now = EntityDates::now();
        $claimSummaries = [];

        foreach ($claims as $i => $claimData) {
            $claim = new Claim($i + 1, $sessionId, $claimData['statement'], 0.8, ClaimStatus::Supported, $now);

            $citations = [];
            foreach ($claimData['citationTexts'] as $j => $text) {
                $citations[] = new Citation(($i * 10) + $j + 1, (int) $claim->id, $j + 1, $text, $now);
            }

            $claimSummaries[] = new ClaimSummary($claim, $citations);
        }

        $contradictions = [];
        foreach ($contradictionSeverities as $i => $severity) {
            $contradictions[] = new Contradiction($i + 1, $sessionId, 1, 2, 'Conflicting figures.', $severity, false, $now);
        }

        return new ResearchSummary(
            sessionId: $sessionId,
            correlationId: 'corr-' . $sessionId,
            topic: 'Test Topic',
            topicCluster: null,
            claims: $claimSummaries,
            entities: [],
            unresolvedContradictions: $contradictions,
            sourceDiversity: new DiversityReport(totalEvidence: 3, distinctDomains: 2, distinctSourceTypes: 1, diversityScore: 0.6),
            timeline: [],
            overallConfidence: $overallConfidence,
            generatedAt: $now,
        );
    }
}

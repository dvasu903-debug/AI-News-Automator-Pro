<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Scoring;

use AINewsAutomator\Research\Contracts\ResearchConfidenceInterface;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\EvidenceRelationship;

/**
 * Primarily deterministic (evidence count + relationship mix), not an
 * AI call per claim — scoring every claim via AIManager would be
 * expensive at volume and adds latency to a step that doesn't need
 * subjective judgment for its dominant signal: how much, and how
 * contradictory, the supporting evidence is. Kept fast and free by
 * design; see module README for the reasoning and the extension point
 * for an optional AI-assisted refinement pass.
 */
final class CompositeConfidenceScorer implements ResearchConfidenceInterface
{
    public function scoreClaim(Claim $claim, array $links): float
    {
        if ($links === []) {
            return 0.0;
        }

        $supporting = 0;
        $contradicting = 0;

        foreach ($links as $link) {
            if ($link->relationship === EvidenceRelationship::Supports) {
                $supporting++;
            } else {
                $contradicting++;
            }
        }

        if ($contradicting > 0 && $supporting === 0) {
            return 0.0;
        }

        // Diminishing returns: the first two or three corroborating
        // pieces of evidence matter far more than the tenth.
        $supportScore = 1.0 - (1.0 / (1.0 + $supporting));
        $contradictionPenalty = min(0.9, $contradicting * 0.3);

        return round(max(0.0, min(1.0, $supportScore - $contradictionPenalty)), 4);
    }

    public function scoreSession(array $claims): float
    {
        if ($claims === []) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $claim->confidenceScore;
        }

        return round($total / count($claims), 4);
    }
}

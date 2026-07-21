<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\DTO\DiversityReport;
use AINewsAutomator\Research\Entities\Evidence;

/**
 * Analyzes an evidence set's source diversity (distinct domains/types) —
 * a deterministic input to confidence scoring, not an AI call.
 */
interface SourceDiversityAnalyzerInterface
{
    /**
     * @param list<Evidence> $evidence
     */
    public function analyze(array $evidence): DiversityReport;
}

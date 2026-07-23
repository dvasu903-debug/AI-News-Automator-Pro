<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\ResearchConfidenceInterface;
use AINewsAutomator\Research\Entities\Claim;

final class FakeConfidenceScorer implements ResearchConfidenceInterface
{
    public function __construct(private readonly float $fixedScore = 0.75)
    {
    }

    public function scoreClaim(Claim $claim, array $links): float
    {
        return $this->fixedScore;
    }

    public function scoreSession(array $claims): float
    {
        return $this->fixedScore;
    }
}

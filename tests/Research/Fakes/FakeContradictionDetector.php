<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\ContradictionDetectorInterface;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\Contradiction;

final class FakeContradictionDetector implements ContradictionDetectorInterface
{
    /** @var list<Contradiction> */
    private array $queued = [];
    public int $callCount = 0;

    public function willReturn(Contradiction $contradiction): self
    {
        $this->queued[] = $contradiction;
        return $this;
    }

    public function detectFor(Claim $newClaim, array $existingClaims): array
    {
        $this->callCount++;
        $result = $this->queued;
        $this->queued = [];
        return $result;
    }
}

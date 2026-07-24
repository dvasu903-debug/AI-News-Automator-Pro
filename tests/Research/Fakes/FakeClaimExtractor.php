<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\ClaimExtractorInterface;
use AINewsAutomator\Research\DTO\ExtractedClaimData;
use AINewsAutomator\Research\Entities\Evidence;

final class FakeClaimExtractor implements ClaimExtractorInterface
{
    /** @var list<ExtractedClaimData> */
    private array $queued = [];
    public int $callCount = 0;

    public function willReturn(ExtractedClaimData $data): self
    {
        $this->queued[] = $data;
        return $this;
    }

    public function extract(Evidence $evidence): array
    {
        $this->callCount++;
        $result = $this->queued;
        $this->queued = [];
        return $result;
    }
}

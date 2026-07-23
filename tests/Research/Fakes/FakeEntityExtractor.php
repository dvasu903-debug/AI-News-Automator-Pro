<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\EntityExtractorInterface;
use AINewsAutomator\Research\DTO\ExtractedEntityData;
use AINewsAutomator\Research\Entities\Evidence;

final class FakeEntityExtractor implements EntityExtractorInterface
{
    /** @var list<ExtractedEntityData> */
    private array $queued = [];

    public function willReturn(ExtractedEntityData $data): self
    {
        $this->queued[] = $data;
        return $this;
    }

    public function extract(Evidence $evidence): array
    {
        $result = $this->queued;
        $this->queued = [];
        return $result;
    }
}

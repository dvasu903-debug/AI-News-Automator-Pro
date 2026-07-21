<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\DTO\ExtractedEntityData;
use AINewsAutomator\Research\Entities\Evidence;

/**
 * Extracts named entities (person/organization/place/event) from one
 * piece of Evidence. Detection only — never persists (ResearchSessionManager does).
 */
interface EntityExtractorInterface
{
    /**
     * @return list<ExtractedEntityData>
     */
    public function extract(Evidence $evidence): array;
}

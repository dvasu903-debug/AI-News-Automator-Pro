<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\DTO;

/**
 * Raw entity-extraction output from EntityExtractorInterface, before it
 * becomes a persisted ExtractedEntity entity (same separation reasoning
 * as ExtractedClaimData).
 */
final class ExtractedEntityData
{
    public function __construct(
        public readonly string $name,
        public readonly string $entityType,
    ) {
    }
}

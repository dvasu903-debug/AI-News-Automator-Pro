<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\DTO;

/**
 * Raw claim-extraction output from ClaimExtractorInterface, before it
 * becomes a persisted Claim entity. Kept separate from the Claim entity
 * itself so extraction (an AI-facing concern) doesn't need to know about
 * persistence (session id, database row shape) — ResearchSessionManager
 * is what turns this into a Claim.
 */
final class ExtractedClaimData
{
    public function __construct(
        public readonly string $statement,
        public readonly float $extractionConfidence,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\Citation;

/**
 * No update() method — citations are write-once (immutable provenance).
 */
interface CitationRepositoryInterface
{
    public function record(Citation $citation): int;

    /**
     * @return list<Citation>
     */
    public function forClaim(int $claimId): array;

    /**
     * @return list<Citation>
     */
    public function forSession(int $sessionId): array;
}

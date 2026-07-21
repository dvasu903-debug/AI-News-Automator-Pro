<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

use AINewsAutomator\Sources\DTO\NormalizedItem;

/**
 * Fingerprint-based deduplication (approved Decision 1 — a lightweight
 * table, not a reuse-only check against published articles). Prevents
 * reprocessing items that were fetched and rejected, not just ones that
 * became a published article.
 */
interface DeduplicationInterface
{
    public function isDuplicate(int $sourceId, NormalizedItem $item): bool;

    /**
     * Records the item as seen. If already recorded, updates last_seen
     * rather than erroring — a feed re-listing an old item is normal,
     * not an exceptional case.
     */
    public function markSeen(int $sourceId, NormalizedItem $item, string $status): void;
}

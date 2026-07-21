<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Dedup;

use AINewsAutomator\Sources\Contracts\DeduplicationInterface;
use AINewsAutomator\Sources\Contracts\SourceItemRepositoryInterface;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Storage\Entities\EntityDates;

final class FingerprintDeduplicator implements DeduplicationInterface
{
    public function __construct(private readonly SourceItemRepositoryInterface $repository)
    {
    }

    public function isDuplicate(int $sourceId, NormalizedItem $item): bool
    {
        return $this->repository->find($sourceId, $item->fingerprint()) !== null;
    }

    public function markSeen(int $sourceId, NormalizedItem $item, string $status): void
    {
        $now = EntityDates::now();
        $itemStatus = SourceItemStatus::from($status);

        $existing = $this->repository->find($sourceId, $item->fingerprint());

        $record = $existing !== null
            ? $existing->withLastSeen($now, $itemStatus)
            : new SourceItemFingerprint(null, $sourceId, $item->fingerprint(), $now, $now, $itemStatus);

        $this->repository->upsert($record);
    }
}

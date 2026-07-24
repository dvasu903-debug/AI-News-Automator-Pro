<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources\Fakes;

use AINewsAutomator\Sources\Contracts\SourceItemRepositoryInterface;
use AINewsAutomator\Sources\Dedup\SourceItemFingerprint;

/**
 * In-memory fake for dedup tests — mirrors the FakeAiRequestRepository/
 * FakeMetricsRepository pattern from AI's test suite.
 */
final class FakeSourceItemRepository implements SourceItemRepositoryInterface
{
    /** @var array<string, SourceItemFingerprint> */
    public array $rows = [];

    public function find(int $sourceId, string $fingerprint): ?SourceItemFingerprint
    {
        return $this->rows[$sourceId . ':' . $fingerprint] ?? null;
    }

    public function upsert(SourceItemFingerprint $item): void
    {
        $this->rows[$item->sourceId . ':' . $item->fingerprint] = $item;
    }

    public function purgeOlderThan(int $days): int
    {
        return 0;
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

use AINewsAutomator\Sources\Dedup\SourceItemFingerprint;

/**
 * Persistence for the fingerprint table (`ana_source_items`) — a Sources-
 * owned table, created via Sources' own migrations reusing Storage's
 * migration classes (ADR-0006), not a Storage-owned repository. Kept
 * separate from DeduplicationInterface: this is the storage contract;
 * DeduplicationInterface is the domain-facing dedup operation built on
 * top of it (the same separation Storage drew between LogRepositoryInterface
 * and Core's LoggerInterface).
 */
interface SourceItemRepositoryInterface
{
    public function find(int $sourceId, string $fingerprint): ?SourceItemFingerprint;

    public function upsert(SourceItemFingerprint $item): void;

    public function purgeOlderThan(int $days): int;
}

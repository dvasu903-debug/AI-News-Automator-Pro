<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources;

use AINewsAutomator\Sources\Dedup\FingerprintDeduplicator;
use AINewsAutomator\Sources\Dedup\SourceItemStatus;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Tests\Sources\Fakes\FakeSourceItemRepository;
use PHPUnit\Framework\TestCase;

final class FingerprintDeduplicatorTest extends TestCase
{
    public function test_unseen_item_is_not_a_duplicate(): void
    {
        $dedup = new FingerprintDeduplicator(new FakeSourceItemRepository());
        $item = new NormalizedItem(url: 'https://example.test/a');

        $this->assertFalse($dedup->isDuplicate(1, $item));
    }

    public function test_marked_seen_item_becomes_a_duplicate(): void
    {
        $repository = new FakeSourceItemRepository();
        $dedup = new FingerprintDeduplicator($repository);
        $item = new NormalizedItem(url: 'https://example.test/a');

        $dedup->markSeen(1, $item, SourceItemStatus::Seen->value);

        $this->assertTrue($dedup->isDuplicate(1, $item));
    }

    public function test_rejected_item_still_counts_as_duplicate(): void
    {
        // Explicit approved purpose: "prevent reprocessing rejected items."
        $repository = new FakeSourceItemRepository();
        $dedup = new FingerprintDeduplicator($repository);
        $item = new NormalizedItem(url: 'https://example.test/a');

        $dedup->markSeen(1, $item, SourceItemStatus::Rejected->value);

        $this->assertTrue($dedup->isDuplicate(1, $item), 'A rejected item must never be reprocessed.');
    }

    public function test_same_item_from_different_sources_is_not_a_duplicate(): void
    {
        $repository = new FakeSourceItemRepository();
        $dedup = new FingerprintDeduplicator($repository);
        $item = new NormalizedItem(url: 'https://example.test/a');

        $dedup->markSeen(1, $item, SourceItemStatus::Seen->value);

        $this->assertFalse($dedup->isDuplicate(2, $item), 'Dedup is scoped per source, not global.');
    }

    public function test_guid_takes_precedence_over_url_for_fingerprinting(): void
    {
        $repository = new FakeSourceItemRepository();
        $dedup = new FingerprintDeduplicator($repository);

        $original = new NormalizedItem(url: 'https://example.test/old-url', guid: 'stable-guid-123');
        $dedup->markSeen(1, $original, SourceItemStatus::Seen->value);

        // Same GUID, different (republished) URL — still a duplicate.
        $republished = new NormalizedItem(url: 'https://example.test/new-url', guid: 'stable-guid-123');

        $this->assertTrue($dedup->isDuplicate(1, $republished));
    }
}

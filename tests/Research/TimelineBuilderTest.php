<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Research\Timeline\TimelineBuilder;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\Research\Fakes\FakeEvidenceRepository;
use PHPUnit\Framework\TestCase;

final class TimelineBuilderTest extends TestCase
{
    public function test_empty_session_returns_empty_timeline(): void
    {
        $builder = new TimelineBuilder(new FakeEvidenceRepository());
        $this->assertSame([], $builder->buildFor(1));
    }

    public function test_undated_evidence_is_excluded(): void
    {
        $repo = new FakeEvidenceRepository();
        $repo->record(new Evidence(null, 1, 'https://x.test/a', 'rss', 'x.test', null, 'snippet', null, EntityDates::now()));

        $builder = new TimelineBuilder($repo);
        $this->assertSame([], $builder->buildFor(1));
    }

    public function test_entries_are_chronologically_ordered(): void
    {
        $repo = new FakeEvidenceRepository();
        $repo->record(new Evidence(null, 1, 'https://x.test/late', 'rss', 'x.test', null, 'late event', new \DateTimeImmutable('2026-06-01'), EntityDates::now()));
        $repo->record(new Evidence(null, 1, 'https://x.test/early', 'rss', 'x.test', null, 'early event', new \DateTimeImmutable('2026-01-01'), EntityDates::now()));

        $builder = new TimelineBuilder($repo);
        $entries = $builder->buildFor(1);

        $this->assertCount(2, $entries);
        $this->assertSame('early event', $entries[0]->description);
        $this->assertSame('late event', $entries[1]->description);
    }

    public function test_only_includes_evidence_for_requested_session(): void
    {
        $repo = new FakeEvidenceRepository();
        $repo->record(new Evidence(null, 1, 'https://x.test/a', 'rss', 'x.test', null, 'session 1', new \DateTimeImmutable('2026-01-01'), EntityDates::now()));
        $repo->record(new Evidence(null, 2, 'https://x.test/b', 'rss', 'x.test', null, 'session 2', new \DateTimeImmutable('2026-01-01'), EntityDates::now()));

        $builder = new TimelineBuilder($repo);
        $entries = $builder->buildFor(1);

        $this->assertCount(1, $entries);
        $this->assertSame('session 1', $entries[0]->description);
    }
}

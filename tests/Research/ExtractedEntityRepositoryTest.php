<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Repositories\ExtractedEntityRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 3 — see
 * EvidenceRepositoryTest's docblock for the testing approach rationale.
 */
final class ExtractedEntityRepositoryTest extends TestCase
{
    private ExtractedEntityRepository $repository;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->createTable('wp_ana_research_entities');

        $this->repository = new ExtractedEntityRepository(new Connection());
    }

    public function test_first_mention_creates_entity_with_count_one(): void
    {
        $this->repository->recordOrIncrement(1, 'Acme Corp', 'organization');

        $results = $this->repository->forSession(1);

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]->mentionCount);
    }

    public function test_second_mention_increments_rather_than_duplicates(): void
    {
        $this->repository->recordOrIncrement(1, 'Acme Corp', 'organization');
        $this->repository->recordOrIncrement(1, 'Acme Corp', 'organization');
        $this->repository->recordOrIncrement(1, 'Acme Corp', 'organization');

        $results = $this->repository->forSession(1);

        $this->assertCount(1, $results, 'Repeated mentions must increment one row, not create duplicates.');
        $this->assertSame(3, $results[0]->mentionCount);
    }

    public function test_different_entity_type_with_same_name_is_a_distinct_entity(): void
    {
        // "Washington" the place vs "Washington" the person are distinct.
        $this->repository->recordOrIncrement(1, 'Washington', 'place');
        $this->repository->recordOrIncrement(1, 'Washington', 'person');

        $results = $this->repository->forSession(1);

        $this->assertCount(2, $results);
    }

    public function test_same_name_different_session_are_independent(): void
    {
        $this->repository->recordOrIncrement(1, 'Acme Corp', 'organization');
        $this->repository->recordOrIncrement(2, 'Acme Corp', 'organization');

        $this->assertCount(1, $this->repository->forSession(1));
        $this->assertCount(1, $this->repository->forSession(2));
        $this->assertSame(1, $this->repository->forSession(1)[0]->mentionCount, 'Sessions must not share mention counts.');
    }
}

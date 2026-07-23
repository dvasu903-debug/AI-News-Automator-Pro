<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\Evidence;
use AINewsAutomator\Research\Repositories\EvidenceRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 3: exercises the
 * REAL repository (SQL-generating) implementation against Storage's
 * FakeWpdb, not the in-memory test double used for orchestration tests —
 * same pattern as tests/AI/PromptTemplateTest.php and
 * tests/Sources/PromptTemplateTest.php.
 */
final class EvidenceRepositoryTest extends TestCase
{
    private EvidenceRepository $repository;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->createTable('wp_ana_research_evidence');

        $this->repository = new EvidenceRepository(new Connection());
    }

    private function evidence(int $sessionId = 1, string $url = 'https://example.test/article'): Evidence
    {
        return new Evidence(null, $sessionId, $url, 'rss', 'example.test', 0.8, 'snippet text', null, EntityDates::now());
    }

    public function test_record_and_find(): void
    {
        $id = $this->repository->record($this->evidence());

        $found = $this->repository->find($id);

        $this->assertNotNull($found);
        $this->assertSame('https://example.test/article', $found->sourceUrl);
        $this->assertSame(0.8, $found->credibilityScore);
    }

    public function test_find_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->repository->find(999));
    }

    public function test_for_session_returns_only_that_sessions_evidence(): void
    {
        $this->repository->record($this->evidence(sessionId: 1, url: 'https://a.test/x'));
        $this->repository->record($this->evidence(sessionId: 2, url: 'https://b.test/y'));

        $results = $this->repository->forSession(1);

        $this->assertCount(1, $results);
        $this->assertSame('https://a.test/x', $results[0]->sourceUrl);
    }

    public function test_invalid_url_fails_validation(): void
    {
        $this->expectException(ValidationException::class);
        $this->repository->record($this->evidence(url: 'not a url'));
    }

    public function test_empty_domain_fails_validation(): void
    {
        $invalid = new Evidence(null, 1, 'https://x.test/a', 'rss', '', null, null, null, EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }

    public function test_invalid_session_id_fails_validation(): void
    {
        $invalid = new Evidence(null, 0, 'https://x.test/a', 'rss', 'x.test', null, null, null, EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }

    public function test_no_update_method_exists_on_the_interface(): void
    {
        // Structural confirmation of "immutable provenance" — this test
        // fails to compile (not just fails at runtime) if an update()
        // method is ever added to the interface, since it would then be
        // reasonable for someone to expect this test to exercise it.
        $this->assertFalse(
            method_exists(\AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface::class, 'update'),
            'EvidenceRepositoryInterface must never gain an update() method — evidence is immutable once recorded.'
        );
    }
}

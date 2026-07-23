<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\Contradiction;
use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Research\Repositories\ContradictionRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 3 — see
 * EvidenceRepositoryTest's docblock for the testing approach rationale.
 */
final class ContradictionRepositoryTest extends TestCase
{
    private ContradictionRepository $repository;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->createTable('wp_ana_research_contradictions');

        $this->repository = new ContradictionRepository(new Connection());
    }

    private function contradiction(int $sessionId = 1, int $a = 1, int $b = 2, ContradictionSeverity $severity = ContradictionSeverity::Medium): Contradiction
    {
        return new Contradiction(null, $sessionId, $a, $b, 'Conflicting figures.', $severity, false, EntityDates::now());
    }

    public function test_record_and_for_session(): void
    {
        $this->repository->record($this->contradiction());

        $results = $this->repository->forSession(1);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->resolved);
    }

    public function test_resolve_flips_resolved_flag(): void
    {
        $id = $this->repository->record($this->contradiction());

        $this->repository->resolve($id);

        $results = $this->repository->forSession(1);
        $this->assertTrue($results[0]->resolved);
    }

    public function test_unresolved_only_filter(): void
    {
        $resolvedId = $this->repository->record($this->contradiction(a: 1, b: 2));
        $this->repository->record($this->contradiction(a: 3, b: 4));
        $this->repository->resolve($resolvedId);

        $unresolvedOnly = $this->repository->forSession(1, unresolvedOnly: true);

        $this->assertCount(1, $unresolvedOnly);
    }

    public function test_self_contradiction_fails_validation(): void
    {
        $invalid = new Contradiction(null, 1, 5, 5, 'x', ContradictionSeverity::Low, false, EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }

    public function test_empty_description_fails_validation(): void
    {
        $invalid = new Contradiction(null, 1, 1, 2, '', ContradictionSeverity::Low, false, EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }
}

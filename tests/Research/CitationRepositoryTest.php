<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Research\Repositories\CitationRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for release-candidate audit Issue 3 — see
 * EvidenceRepositoryTest's docblock for the testing approach rationale.
 */
final class CitationRepositoryTest extends TestCase
{
    private CitationRepository $repository;

    protected function setUp(): void
    {
        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->createTable('wp_ana_research_citations');
        $wpdb->createTable('wp_ana_research_claims');

        $this->repository = new CitationRepository(new Connection());
    }

    private function citation(int $claimId = 1, int $evidenceId = 1): Citation
    {
        return new Citation(null, $claimId, $evidenceId, 'Example Domain. Retrieved from https://example.test.', EntityDates::now());
    }

    public function test_record_and_for_claim(): void
    {
        $this->repository->record($this->citation(claimId: 1));

        $results = $this->repository->forClaim(1);

        $this->assertCount(1, $results);
        $this->assertSame('Example Domain. Retrieved from https://example.test.', $results[0]->citationText);
    }

    public function test_for_claim_scoped_correctly(): void
    {
        $this->repository->record($this->citation(claimId: 1));
        $this->repository->record($this->citation(claimId: 2));

        $this->assertCount(1, $this->repository->forClaim(1));
        $this->assertCount(1, $this->repository->forClaim(2));
    }

    public function test_empty_citation_text_fails_validation(): void
    {
        $invalid = new Citation(null, 1, 1, '', EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }

    public function test_invalid_claim_id_fails_validation(): void
    {
        $invalid = new Citation(null, 0, 1, 'Text.', EntityDates::now());

        $this->expectException(ValidationException::class);
        $this->repository->record($invalid);
    }

    public function test_no_update_method_exists_on_the_interface(): void
    {
        $this->assertFalse(
            method_exists(\AINewsAutomator\Research\Contracts\CitationRepositoryInterface::class, 'update'),
            'CitationRepositoryInterface must never gain an update() method — citations are write-once.'
        );
    }
}

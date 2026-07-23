<?php
/**
 * Exercises DraftSeoRepository against the REAL Connection class, backed
 * by tests/Storage/FakeWpdb.php — same pattern as
 * PublishingProfileRepositoryTest (Milestone 2).
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Publishing\Repositories\DraftSeoRepository;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Tests\Storage\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class DraftSeoRepositoryTest extends TestCase
{
    private FakeWpdb $wpdb;
    private DraftSeoRepository $repository;

    protected function setUp(): void
    {
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->createTable('wp_ana_draft_seo');

        $this->repository = new DraftSeoRepository(new Connection());
    }

    private function seo(int $postId, string $metaTitle = 'Title'): DraftSeo
    {
        return new DraftSeo(
            id: null,
            postId: $postId,
            metaTitle: $metaTitle,
            metaDescription: 'Description.',
            focusKeyword: 'keyword',
            canonicalUrl: null,
            robotsDirectives: 'index,follow',
        );
    }

    public function test_upsert_inserts_when_no_row_exists_for_post_id(): void
    {
        $result = $this->repository->upsert($this->seo(501));

        $this->assertNotNull($result->id);
        $this->assertSame(501, $result->postId);
        $this->assertNotNull($result->createdAt);
        $this->assertNotNull($result->updatedAt);
    }

    public function test_find_by_post_id_round_trips(): void
    {
        $this->repository->upsert($this->seo(501, 'Original Title'));

        $found = $this->repository->findByPostId(501);

        $this->assertNotNull($found);
        $this->assertSame('Original Title', $found->metaTitle);
        $this->assertSame('index,follow', $found->robotsDirectives);
    }

    public function test_find_by_post_id_returns_null_when_absent(): void
    {
        $this->assertNull($this->repository->findByPostId(999));
    }

    public function test_upsert_updates_existing_row_for_same_post_id_rather_than_duplicating(): void
    {
        $this->repository->upsert($this->seo(501, 'First Title'));
        $updated = $this->repository->upsert($this->seo(501, 'Second Title'));

        $found = $this->repository->findByPostId(501);

        $this->assertSame($updated->id, $found->id);
        $this->assertSame('Second Title', $found->metaTitle);
    }
}

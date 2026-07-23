<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\Repositories\DraftRepository;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers DraftRepository's composition of ArticleRepositoryInterface
 * (approved Decision 1 — wrap, don't extend). create/findBySourceUrl/
 * isGenerated delegate straight through; update/delete exercise the
 * WordPress post-function stubs added to tests/bootstrap.php for this
 * module, since ArticleRepositoryInterface deliberately doesn't expose
 * those operations.
 */
final class DraftRepositoryTest extends TestCase
{
    private FakeArticleRepository $articles;
    private DraftRepository $drafts;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];
        $GLOBALS['__ana_test_postmeta'] = [];

        $this->articles = new FakeArticleRepository();
        $this->drafts = new DraftRepository($this->articles);
    }

    public function test_create_delegates_to_article_repository(): void
    {
        $id = $this->drafts->create('Title', 'Content', ['_ana_confidence' => 0.9]);

        $this->assertSame(1, $id);
        $this->assertSame(['Title', 'Content', ['_ana_confidence' => 0.9]], $this->articles->createCalls[0]);
    }

    public function test_update_merges_title_content_and_meta_without_touching_article_repository(): void
    {
        $this->drafts->update(42, title: 'New Title', content: 'New Content', meta: ['_ana_status' => 'reviewed']);

        $this->assertSame('New Title', $GLOBALS['__ana_test_posts'][42]['post_title']);
        $this->assertSame('New Content', $GLOBALS['__ana_test_posts'][42]['post_content']);
        $this->assertSame('reviewed', $GLOBALS['__ana_test_postmeta'][42]['_ana_status']);
        $this->assertSame([], $this->articles->createCalls, 'update() must not call the frozen Article contract.');
    }

    public function test_update_with_only_meta_does_not_call_wp_update_post(): void
    {
        $this->drafts->update(7, meta: ['_ana_confidence' => 0.5]);

        $this->assertArrayNotHasKey(7, $GLOBALS['__ana_test_posts']);
        $this->assertSame(0.5, $GLOBALS['__ana_test_postmeta'][7]['_ana_confidence']);
    }

    public function test_delete_removes_post_and_meta(): void
    {
        $GLOBALS['__ana_test_posts'][9] = ['post_title' => 'x'];
        $GLOBALS['__ana_test_postmeta'][9] = ['k' => 'v'];

        $this->drafts->delete(9);

        $this->assertArrayNotHasKey(9, $GLOBALS['__ana_test_posts']);
        $this->assertArrayNotHasKey(9, $GLOBALS['__ana_test_postmeta']);
    }

    public function test_find_by_source_url_delegates_to_article_repository(): void
    {
        $this->articles->bySourceUrlReturn = 123;

        $this->assertSame(123, $this->drafts->findBySourceUrl('https://example.com/a'));
        $this->assertSame(['https://example.com/a'], $this->articles->bySourceUrlCalls[0]);
    }

    public function test_is_generated_delegates_to_article_repository(): void
    {
        $this->articles->isGeneratedReturn = true;

        $this->assertTrue($this->drafts->isGenerated(5));
        $this->assertSame([5], $this->articles->isGeneratedCalls[0]);
    }
}

/**
 * @internal test double for ArticleRepositoryInterface, scoped to this
 * test file only — Publishing's own tests should not depend on
 * Storage's real repository or its FakeWpdb wiring, since DraftRepository
 * only ever interacts with the interface, never the implementation.
 */
final class FakeArticleRepository implements ArticleRepositoryInterface
{
    public int $nextId = 1;
    public ?int $bySourceUrlReturn = null;
    public bool $isGeneratedReturn = false;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $createCalls = [];
    /** @var list<array{0: string}> */
    public array $bySourceUrlCalls = [];
    /** @var list<array{0: int}> */
    public array $isGeneratedCalls = [];

    public function createDraft(string $title, string $content, array $meta = []): int
    {
        $this->createCalls[] = [$title, $content, $meta];
        return $this->nextId++;
    }

    public function approve(int $postId): bool
    {
        return true;
    }

    public function pendingReview(): array
    {
        return [];
    }

    public function bySourceUrl(string $url): ?int
    {
        $this->bySourceUrlCalls[] = [$url];
        return $this->bySourceUrlReturn;
    }

    public function isGenerated(int $postId): bool
    {
        $this->isGeneratedCalls[] = [$postId];
        return $this->isGeneratedReturn;
    }
}

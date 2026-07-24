<?php
/**
 * Covers MetaTagBuilder: the primary tag-construction target, kept
 * separate from SeoHeadRenderer per the owner-approved refinement.
 *
 * @package AINewsAutomator\Tests\Seo\Services
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Services;

use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Seo\Services\CanonicalUrlResolver;
use AINewsAutomator\Seo\Services\MetaTagBuilder;
use AINewsAutomator\Seo\Services\SchemaOrgGenerator;
use AINewsAutomator\Tests\Seo\Fakes\FakeDraftSeoRepository;
use PHPUnit\Framework\TestCase;

final class MetaTagBuilderTest extends TestCase
{
    private FakeDraftSeoRepository $seoRepository;
    private MetaTagBuilder $builder;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];
        $GLOBALS['__ana_test_permalinks'] = [];
        $GLOBALS['__ana_test_thumbnails'] = [];
        $GLOBALS['__ana_test_bloginfo'] = ['name' => 'Test Site'];

        $this->seoRepository = new FakeDraftSeoRepository();
        $this->builder = new MetaTagBuilder($this->seoRepository, new SchemaOrgGenerator(), new CanonicalUrlResolver());
    }

    public function test_returns_null_when_no_seo_row_exists(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'Title', 'post_content' => 'Body.'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/title';

        $this->assertNull($this->builder->build(1));
    }

    public function test_returns_null_when_post_missing(): void
    {
        $this->seoRepository->seed(new DraftSeo(1, 5, 'T', 'D', 'k', null, 'index,follow'));

        $this->assertNull($this->builder->build(5));
    }

    public function test_returns_null_when_no_permalink_resolvable(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'Title', 'post_content' => 'Body.'];
        $this->seoRepository->seed(new DraftSeo(1, 1, 'T', 'D', 'k', null, 'index,follow'));
        // No permalink seeded -> get_permalink() returns false.

        $this->assertNull($this->builder->build(1));
    }

    public function test_builds_complete_tag_data_without_image(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'Fallback Title', 'post_content' => 'Body.', 'post_date' => '2026-01-01 10:00:00'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/article';
        $this->seoRepository->seed(new DraftSeo(1, 1, 'Meta Title', 'Meta description.', 'keyword', null, 'index,follow'));

        $data = $this->builder->build(1);

        $this->assertNotNull($data);
        $this->assertSame('https://example.test/article', $data->canonicalUrl);
        $this->assertSame('index,follow', $data->robotsDirectives);
        $this->assertSame('Meta Title', $data->openGraph['og:title']);
        $this->assertSame('Meta description.', $data->openGraph['og:description']);
        $this->assertSame('article', $data->openGraph['og:type']);
        $this->assertSame('https://example.test/article', $data->openGraph['og:url']);
        $this->assertSame('Test Site', $data->openGraph['og:site_name']);
        $this->assertArrayNotHasKey('og:image', $data->openGraph);
        $this->assertSame('summary', $data->twitterCard['twitter:card']);
        $this->assertArrayNotHasKey('twitter:image', $data->twitterCard);
        $this->assertSame('NewsArticle', $data->jsonLd['@type']);
    }

    public function test_builds_tag_data_with_featured_image(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'Title', 'post_content' => 'Body.'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/article';
        $GLOBALS['__ana_test_thumbnails'][1] = 'https://example.test/image.jpg';
        $this->seoRepository->seed(new DraftSeo(1, 1, 'Title', 'Description', 'k', null, 'index,follow'));

        $data = $this->builder->build(1);

        $this->assertNotNull($data);
        $this->assertSame('https://example.test/image.jpg', $data->openGraph['og:image']);
        $this->assertSame('https://example.test/image.jpg', $data->twitterCard['twitter:image']);
        $this->assertSame('summary_large_image', $data->twitterCard['twitter:card']);
        $this->assertSame(['https://example.test/image.jpg'], $data->jsonLd['image']);
    }

    public function test_falls_back_to_post_title_when_meta_title_absent(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => '<b>Raw</b> Title', 'post_content' => 'Body.'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/article';
        $this->seoRepository->seed(new DraftSeo(1, 1, null, null, null, null, 'index,follow'));

        $data = $this->builder->build(1);

        $this->assertNotNull($data);
        $this->assertSame('Raw Title', $data->openGraph['og:title']);
    }
}

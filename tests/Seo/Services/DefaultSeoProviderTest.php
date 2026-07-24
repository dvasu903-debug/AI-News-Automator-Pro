<?php
/**
 * Covers DefaultSeoProvider: a thin delegation to MetaTagBuilder. Kept
 * intentionally minimal — the real construction logic is
 * MetaTagBuilderTest's responsibility.
 *
 * @package AINewsAutomator\Tests\Seo\Services
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Services;

use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Seo\Services\CanonicalUrlResolver;
use AINewsAutomator\Seo\Services\DefaultSeoProvider;
use AINewsAutomator\Seo\Services\MetaTagBuilder;
use AINewsAutomator\Seo\Services\SchemaOrgGenerator;
use AINewsAutomator\Tests\Seo\Fakes\FakeDraftSeoRepository;
use PHPUnit\Framework\TestCase;

final class DefaultSeoProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];
        $GLOBALS['__ana_test_permalinks'] = [];
        $GLOBALS['__ana_test_thumbnails'] = [];
        $GLOBALS['__ana_test_bloginfo'] = ['name' => 'Test Site'];
    }

    private function provider(FakeDraftSeoRepository $repo): DefaultSeoProvider
    {
        $builder = new MetaTagBuilder($repo, new SchemaOrgGenerator(), new CanonicalUrlResolver());

        return new DefaultSeoProvider($builder);
    }

    public function test_delegates_to_meta_tag_builder(): void
    {
        $repo = new FakeDraftSeoRepository();
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'Title', 'post_content' => 'Body.'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/a';
        $repo->seed(new DraftSeo(1, 1, 'T', 'D', 'k', null, 'index,follow'));

        $data = $this->provider($repo)->provide(1);

        $this->assertNotNull($data);
        $this->assertSame('https://example.test/a', $data->canonicalUrl);
    }

    public function test_returns_null_when_builder_returns_null(): void
    {
        $repo = new FakeDraftSeoRepository();

        $this->assertNull($this->provider($repo)->provide(999));
    }
}

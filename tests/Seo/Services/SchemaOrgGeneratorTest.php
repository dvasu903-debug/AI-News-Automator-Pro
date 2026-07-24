<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Services;

use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Seo\Services\SchemaOrgGenerator;
use PHPUnit\Framework\TestCase;

final class SchemaOrgGeneratorTest extends TestCase
{
    private SchemaOrgGenerator $generator;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_bloginfo'] = ['name' => 'Test Site'];
        $this->generator = new SchemaOrgGenerator();
    }

    private function post(array $overrides = []): \WP_Post
    {
        return new \WP_Post(array_merge([
            'ID' => 1,
            'post_title' => 'Article Title',
            'post_date' => '2026-01-15 09:30:00',
            'post_modified' => '2026-01-16 10:00:00',
        ], $overrides));
    }

    public function test_generates_news_article_type(): void
    {
        $seo = new DraftSeo(1, 1, null, null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/a', null);

        $this->assertSame('NewsArticle', $jsonLd['@type']);
        $this->assertSame('https://schema.org', $jsonLd['@context']);
    }

    public function test_uses_meta_title_when_present(): void
    {
        $seo = new DraftSeo(1, 1, 'Custom Headline', null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/a', null);

        $this->assertSame('Custom Headline', $jsonLd['headline']);
    }

    public function test_falls_back_to_post_title_when_no_meta_title(): void
    {
        $seo = new DraftSeo(1, 1, null, null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(['post_title' => '<b>Raw</b>']), $seo, 'https://example.test/a', null);

        $this->assertSame('Raw', $jsonLd['headline']);
    }

    public function test_dates_are_iso8601(): void
    {
        $seo = new DraftSeo(1, 1, null, null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/a', null);

        $this->assertSame('2026-01-15T09:30:00+00:00', $jsonLd['datePublished']);
        $this->assertSame('2026-01-16T10:00:00+00:00', $jsonLd['dateModified']);
    }

    public function test_includes_image_when_provided(): void
    {
        $seo = new DraftSeo(1, 1, null, null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/a', 'https://example.test/img.jpg');

        $this->assertSame(['https://example.test/img.jpg'], $jsonLd['image']);
    }

    public function test_omits_image_when_absent(): void
    {
        $seo = new DraftSeo(1, 1, null, null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/a', null);

        $this->assertArrayNotHasKey('image', $jsonLd);
    }

    public function test_includes_description_when_present(): void
    {
        $seo = new DraftSeo(1, 1, null, 'A description.', null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/a', null);

        $this->assertSame('A description.', $jsonLd['description']);
    }

    public function test_main_entity_of_page_uses_canonical_url(): void
    {
        $seo = new DraftSeo(1, 1, null, null, null, null, 'index,follow');

        $jsonLd = $this->generator->generate($this->post(), $seo, 'https://example.test/canonical', null);

        $this->assertSame('https://example.test/canonical', $jsonLd['mainEntityOfPage']['@id']);
    }
}

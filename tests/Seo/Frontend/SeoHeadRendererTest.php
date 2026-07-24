<?php
/**
 * Covers SeoHeadRenderer: the module's one output/escaping boundary.
 * The escaping-regression cases here are the "prove the trust boundary
 * holds, don't just reason about it" discipline AiContentGeneratorTest
 * established in Milestone 4, applied to this module's new output
 * contexts (HTML attribute, JSON-LD inside a <script> block).
 *
 * @package AINewsAutomator\Tests\Seo\Frontend
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Frontend;

use AINewsAutomator\Seo\DTO\SeoTagData;
use AINewsAutomator\Seo\Frontend\SeoHeadRenderer;
use AINewsAutomator\Tests\Seo\Fakes\FakeSeoProvider;
use PHPUnit\Framework\TestCase;

final class SeoHeadRendererTest extends TestCase
{
    private FakeSeoProvider $provider;
    private SeoHeadRenderer $renderer;

    protected function setUp(): void
    {
        $this->provider = new FakeSeoProvider();
        $this->renderer = new SeoHeadRenderer($this->provider);
    }

    private function capture(int $postId): string
    {
        ob_start();
        $this->renderer->renderFor($postId);

        return (string) ob_get_clean();
    }

    public function test_renders_nothing_when_provider_returns_null(): void
    {
        $this->provider->provideReturn = null;

        $this->assertSame('', $this->capture(1));
    }

    public function test_renders_canonical_link(): void
    {
        $this->provider->provideReturn = new SeoTagData('https://example.test/a', null, [], [], []);

        $output = $this->capture(1);

        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/a" />', $output);
    }

    public function test_renders_robots_meta_only_when_present(): void
    {
        $this->provider->provideReturn = new SeoTagData('https://example.test/a', 'noindex,nofollow', [], [], []);

        $output = $this->capture(1);

        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow" />', $output);
    }

    public function test_omits_robots_meta_when_absent(): void
    {
        $this->provider->provideReturn = new SeoTagData('https://example.test/a', null, [], [], []);

        $this->assertStringNotContainsString('name="robots"', $this->capture(1));
    }

    public function test_renders_open_graph_and_twitter_tags(): void
    {
        $this->provider->provideReturn = new SeoTagData(
            'https://example.test/a',
            null,
            ['og:title' => 'Hello', 'og:type' => 'article'],
            ['twitter:card' => 'summary'],
            []
        );

        $output = $this->capture(1);

        $this->assertStringContainsString('<meta property="og:title" content="Hello" />', $output);
        $this->assertStringContainsString('<meta property="og:type" content="article" />', $output);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary" />', $output);
    }

    public function test_renders_json_ld_script_block(): void
    {
        $this->provider->provideReturn = new SeoTagData('https://example.test/a', null, [], [], ['@type' => 'NewsArticle']);

        $output = $this->capture(1);

        $this->assertStringContainsString('<script type="application/ld+json">', $output);
        $this->assertStringContainsString('"@type"', $output);
    }

    public function test_omits_json_ld_block_when_empty(): void
    {
        $this->provider->provideReturn = new SeoTagData('https://example.test/a', null, [], [], []);

        $this->assertStringNotContainsString('<script', $this->capture(1));
    }

    // --- Escaping-regression cases ---

    public function test_hostile_og_tag_content_is_escaped_as_an_attribute(): void
    {
        $this->provider->provideReturn = new SeoTagData(
            'https://example.test/a',
            null,
            ['og:title' => '"><script>alert(1)</script>'],
            [],
            []
        );

        $output = $this->capture(1);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
        $this->assertStringContainsString('&quot;&gt;', $output);
    }

    public function test_hostile_canonical_url_is_escaped(): void
    {
        $this->provider->provideReturn = new SeoTagData(
            'https://example.test/"><script>alert(1)</script>',
            null,
            [],
            [],
            []
        );

        $output = $this->capture(1);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
    }

    public function test_hostile_json_ld_value_cannot_break_out_of_script_tag(): void
    {
        $this->provider->provideReturn = new SeoTagData(
            'https://example.test/a',
            null,
            [],
            [],
            ['headline' => '</script><script>alert(1)</script>']
        );

        $output = $this->capture(1);

        // JSON_HEX_TAG converts every "<" character inside the JSON
        // payload into a backslash-u-0-0-3-C escape sequence (built via
        // chr(92) here to avoid this file itself containing a literal
        // unicode escape) — assert that escape sequence is what
        // actually appears, and that the literal closing tag never
        // survives verbatim.
        $escapedLessThan = \chr(92) . 'u003C';

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $output);
        $this->assertStringContainsString($escapedLessThan, $output);
    }

    public function test_hostile_twitter_content_is_escaped(): void
    {
        $this->provider->provideReturn = new SeoTagData(
            'https://example.test/a',
            null,
            [],
            ['twitter:description' => '"><img src=x onerror=alert(1)>'],
            []
        );

        $output = $this->capture(1);

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $output);
    }
}

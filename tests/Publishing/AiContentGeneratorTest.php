<?php
/**
 * Covers AiContentGenerator's trust-boundary responsibilities (ADR-0019,
 * decisions 2-4): no-default-template failure, wp_kses_post()
 * sanitization of the AI-generated body, and esc_html() escaping of
 * deterministically-appended citations. Exercises the REAL AIManager
 * (wired via AIManagerTestFactory, the same pattern AiClaimExtractorTest
 * uses) against AI module's own FakeChatProvider — only the network call
 * is faked, the JSON-decoding/sanitization code under test is genuinely
 * AiContentGenerator's own.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\AI\Prompt\PromptTemplate;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Publishing\Exceptions\ContentGenerationException;
use AINewsAutomator\Publishing\Services\AiContentGenerator;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Tests\AI\Fakes\AIManagerTestFactory;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use AINewsAutomator\Tests\Publishing\Fakes\FakePromptTemplateRepository;
use AINewsAutomator\Tests\Publishing\Fakes\ResearchSummaryFixture;
use PHPUnit\Framework\TestCase;

final class AiContentGeneratorTest extends TestCase
{
    private FakePromptTemplateRepository $templates;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $GLOBALS['__ana_test_transients'] = [];

        $this->templates = new FakePromptTemplateRepository();
        $this->templates->latest = new PromptTemplate(
            1,
            'publishing.article_generation',
            '1.0.0',
            'news',
            'You are a professional news writer.',
            [],
            EntityDates::now()
        );
    }

    private function generatorReturning(string $jsonContent): AiContentGenerator
    {
        $provider = new FakeChatProvider('claude');
        $provider->willReturn(FakeChatProvider::successResponse('claude', $jsonContent));
        $harness = AIManagerTestFactory::build([$provider]);

        return new AiContentGenerator($harness->manager, $this->templates, new OptionBackedConfigRepository([]));
    }

    public function test_throws_when_no_template_is_configured(): void
    {
        $this->templates->latest = null;
        $generator = $this->generatorReturning('{"title":"T","body":"B"}');

        $this->expectException(ContentGenerationException::class);

        $generator->generate(ResearchSummaryFixture::build());
    }

    public function test_throws_on_malformed_response(): void
    {
        $generator = $this->generatorReturning('{"unexpected": true}');

        $this->expectException(ContentGenerationException::class);

        $generator->generate(ResearchSummaryFixture::build());
    }

    public function test_script_tag_in_generated_body_is_stripped(): void
    {
        $generator = $this->generatorReturning(json_encode([
            'title' => 'Safe Title',
            'body'  => '<p>Hello</p><script>alert(1)</script>',
        ]));

        $content = $generator->generate(ResearchSummaryFixture::build());

        $this->assertStringNotContainsString('<script', $content->body);
        $this->assertStringContainsString('<p>Hello</p>', $content->body);
    }

    public function test_citation_text_is_escaped_when_appended(): void
    {
        $generator = $this->generatorReturning(json_encode([
            'title' => 'Title',
            'body'  => '<p>Body.</p>',
        ]));

        $summary = ResearchSummaryFixture::build(claims: [
            ['statement' => 'A claim.', 'citationTexts' => ['<b>Untrusted</b> & co.']],
        ]);

        $content = $generator->generate($summary);

        $this->assertStringNotContainsString('<b>Untrusted</b>', $content->body);
        $this->assertStringContainsString('&lt;b&gt;Untrusted&lt;/b&gt;', $content->body);
        $this->assertStringContainsString('&amp; co.', $content->body);
    }

    public function test_no_citations_appends_nothing_extra(): void
    {
        $generator = $this->generatorReturning(json_encode([
            'title' => 'Title',
            'body'  => '<p>Body.</p>',
        ]));

        $summary = ResearchSummaryFixture::build(claims: [
            ['statement' => 'A claim.', 'citationTexts' => []],
        ]);

        $content = $generator->generate($summary);

        $this->assertStringNotContainsString('Sources', $content->body);
    }

    public function test_title_has_tags_stripped(): void
    {
        $generator = $this->generatorReturning(json_encode([
            'title' => '<b>Bold Title</b>',
            'body'  => '<p>Body.</p>',
        ]));

        $content = $generator->generate(ResearchSummaryFixture::build());

        $this->assertSame('Bold Title', $content->title);
    }
}

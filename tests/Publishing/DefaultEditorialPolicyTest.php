<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\DraftNotFoundException;
use AINewsAutomator\Publishing\Services\DefaultEditorialPolicy;
use AINewsAutomator\Tests\Publishing\Fakes\FakeDraftRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers DefaultEditorialPolicy's scope per ADR-0018: AI-generation
 * disclosure and word-count bounds only — citation/confidence checks
 * are deferred pending ResearchSummary integration.
 */
final class DefaultEditorialPolicyTest extends TestCase
{
    private FakeDraftRepository $drafts;
    private DefaultEditorialPolicy $policy;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];

        $this->drafts = new FakeDraftRepository();
        $this->policy = new DefaultEditorialPolicy($this->drafts);
    }

    private function profile(array $config = []): PublishingProfile
    {
        return new PublishingProfile(1, 'news', 'News', 'standard_publish', 'news', 'manual', $config);
    }

    public function test_throws_when_post_does_not_exist(): void
    {
        $this->expectException(DraftNotFoundException::class);

        $this->policy->evaluate(999, $this->profile());
    }

    public function test_passes_when_no_config_constraints_and_not_generated(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => 'Some manually written content here.'];
        $this->drafts->isGeneratedReturn = false;

        $result = $this->policy->evaluate(1, $this->profile());

        $this->assertTrue($result->passes());
        $this->assertSame([], $result->violations);
    }

    public function test_generated_draft_without_disclosure_acknowledgement_is_rejected(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => 'AI written content.'];
        $this->drafts->isGeneratedReturn = true;

        $result = $this->policy->evaluate(1, $this->profile());

        $this->assertFalse($result->passes());
        $this->assertCount(1, $result->violations);
        $this->assertStringContainsString('disclosure', $result->violations[0]);
    }

    public function test_generated_draft_with_disclosure_acknowledged_passes(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => 'AI written content.'];
        $this->drafts->isGeneratedReturn = true;

        $result = $this->policy->evaluate(1, $this->profile(['ai_disclosure_acknowledged' => true]));

        $this->assertTrue($result->passes());
    }

    public function test_word_count_below_minimum_is_rejected(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => 'Only four words here.'];

        $result = $this->policy->evaluate(1, $this->profile(['min_word_count' => 100]));

        $this->assertFalse($result->passes());
        $this->assertStringContainsString('below', $result->violations[0]);
    }

    public function test_word_count_above_maximum_is_rejected(): void
    {
        $content = implode(' ', array_fill(0, 50, 'word'));
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => $content];

        $result = $this->policy->evaluate(1, $this->profile(['max_word_count' => 10]));

        $this->assertFalse($result->passes());
        $this->assertStringContainsString('above', $result->violations[0]);
    }

    public function test_word_count_within_bounds_passes(): void
    {
        $content = implode(' ', array_fill(0, 50, 'word'));
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => $content];

        $result = $this->policy->evaluate(1, $this->profile(['min_word_count' => 10, 'max_word_count' => 100]));

        $this->assertTrue($result->passes());
    }

    public function test_multiple_violations_are_all_collected(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => 'Short.'];
        $this->drafts->isGeneratedReturn = true;

        $result = $this->policy->evaluate(1, $this->profile(['min_word_count' => 100]));

        $this->assertFalse($result->passes());
        $this->assertCount(2, $result->violations);
    }

    public function test_html_is_stripped_before_counting_words(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_content' => '<p>One two three</p><script>ignored</script>'];

        $result = $this->policy->evaluate(1, $this->profile(['min_word_count' => 5]));

        $this->assertFalse($result->passes());
    }
}

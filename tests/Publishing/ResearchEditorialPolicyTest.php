<?php
/**
 * Covers ResearchEditorialPolicy (approved Decision 5, ADR-0019): a
 * second EditorialPolicyInterface implementation checking
 * citation-count/confidence/contradiction signals from a linked
 * research session, passing trivially when no session is linked.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Services\ResearchEditorialPolicy;
use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Tests\Publishing\Fakes\FakeSessionRepository;
use AINewsAutomator\Tests\Publishing\Fakes\ResearchSummaryFixture;
use PHPUnit\Framework\TestCase;

final class ResearchEditorialPolicyTest extends TestCase
{
    private FakeSessionRepository $sessions;
    private ResearchEditorialPolicy $policy;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_postmeta'] = [];

        $this->sessions = new FakeSessionRepository();
        $this->policy = new ResearchEditorialPolicy($this->sessions);
    }

    private function profile(array $config = []): PublishingProfile
    {
        return new PublishingProfile(1, 'news', 'News', 'standard_publish', 'news', 'manual', $config);
    }

    public function test_passes_trivially_when_no_research_session_linked(): void
    {
        $result = $this->policy->evaluate(1, $this->profile(['min_citation_count' => 5]));

        $this->assertTrue($result->passes());
        $this->assertSame([], $this->sessions->summarizeCalls, 'summarize() must not be called without a linked session.');
    }

    public function test_passes_when_thresholds_are_met(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 42;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(sessionId: 42, overallConfidence: 0.9);

        $result = $this->policy->evaluate(1, $this->profile(['min_citation_count' => 1, 'min_confidence' => 0.5]));

        $this->assertTrue($result->passes());
        $this->assertSame([42], $this->sessions->summarizeCalls);
    }

    public function test_fails_when_citation_count_below_minimum(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 42;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(sessionId: 42, claims: [
            ['statement' => 'A claim.', 'citationTexts' => ['One citation.']],
        ]);

        $result = $this->policy->evaluate(1, $this->profile(['min_citation_count' => 5]));

        $this->assertFalse($result->passes());
        $this->assertStringContainsString('citation', $result->violations[0]);
    }

    public function test_fails_when_confidence_below_minimum(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 42;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(sessionId: 42, overallConfidence: 0.2);

        $result = $this->policy->evaluate(1, $this->profile(['min_confidence' => 0.8]));

        $this->assertFalse($result->passes());
        $this->assertStringContainsString('confidence', $result->violations[0]);
    }

    public function test_fails_on_blocking_contradiction(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 42;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(
            sessionId: 42,
            contradictionSeverities: [ContradictionSeverity::Critical]
        );

        $result = $this->policy->evaluate(1, $this->profile());

        $this->assertFalse($result->passes());
        $this->assertStringContainsString('contradictions', $result->violations[0]);
    }

    public function test_passes_when_contradiction_is_low_severity(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 42;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(
            sessionId: 42,
            contradictionSeverities: [ContradictionSeverity::Low]
        );

        $result = $this->policy->evaluate(1, $this->profile());

        $this->assertTrue($result->passes());
    }

    public function test_multiple_violations_are_all_collected(): void
    {
        $GLOBALS['__ana_test_postmeta'][1]['_ana_research_session_id'] = 42;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(
            sessionId: 42,
            claims: [['statement' => 'A claim.', 'citationTexts' => []]],
            overallConfidence: 0.1,
            contradictionSeverities: [ContradictionSeverity::High]
        );

        $result = $this->policy->evaluate(1, $this->profile(['min_citation_count' => 2, 'min_confidence' => 0.9]));

        $this->assertFalse($result->passes());
        $this->assertCount(3, $result->violations);
    }
}

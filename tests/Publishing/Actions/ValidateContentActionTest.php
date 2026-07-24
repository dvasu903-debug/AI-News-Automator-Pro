<?php
/**
 * Covers ValidateContentAction: it runs BOTH the generic
 * EditorialPolicyInterface binding and ResearchEditorialPolicy, merging
 * violations from both (approved Decision 5), and reads "post_id" from
 * step config or, absent that, from the prior "generate" step's output
 * via WorkflowRunContext::priorOutput().
 *
 * @package AINewsAutomator\Tests\Publishing\Actions
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Actions;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Publishing\Actions\ValidateContentAction;
use AINewsAutomator\Publishing\DTO\EditorialPolicyResult;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Events\PublishingRejectedEvent;
use AINewsAutomator\Publishing\Services\ResearchEditorialPolicy;
use AINewsAutomator\Tests\Publishing\Fakes\FakeEditorialPolicy;
use AINewsAutomator\Tests\Publishing\Fakes\FakeSessionRepository;
use AINewsAutomator\Tests\Publishing\Fakes\InMemoryPublishingProfileRepository;
use AINewsAutomator\Tests\Publishing\Fakes\ResearchSummaryFixture;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use PHPUnit\Framework\TestCase;

final class ValidateContentActionTest extends TestCase
{
    private FakeEditorialPolicy $contentPolicy;
    private FakeSessionRepository $sessions;
    private ResearchEditorialPolicy $researchPolicy;
    private InMemoryPublishingProfileRepository $profiles;
    private EventDispatcher $events;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_postmeta'] = [];

        $this->contentPolicy = new FakeEditorialPolicy();
        $this->sessions = new FakeSessionRepository();
        $this->researchPolicy = new ResearchEditorialPolicy($this->sessions);
        $this->profiles = new InMemoryPublishingProfileRepository();
        $this->events = new EventDispatcher();
        $this->dispatched = [];

        $this->events->addListener(PublishingRejectedEvent::class, function (object $e): void {
            $this->dispatched[] = $e;
        });
    }

    private function action(): ValidateContentAction
    {
        return new ValidateContentAction(
            $this->contentPolicy,
            $this->researchPolicy,
            $this->profiles,
            $this->events,
            new EventMetadataFactory(new CorrelationContext('test'))
        );
    }

    private function context(array $stepConfig, array $priorStepOutputs = []): WorkflowRunContext
    {
        return new WorkflowRunContext(1, 'validate_content', 'corr-1', $stepConfig, $priorStepOutputs);
    }

    public function test_requires_resolvable_post_id(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $result = $this->action()->execute($this->context(['profile_id' => $profile->id()]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('post_id', $result->error);
    }

    public function test_reads_post_id_from_prior_generate_step_output(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $result = $this->action()->execute($this->context(
            ['profile_id' => $profile->id()],
            ['generate' => ['post_id' => 77]]
        ));

        $this->assertTrue($result->isSuccess());
        $this->assertSame(77, $result->output['post_id']);
    }

    public function test_requires_profile_id(): void
    {
        $result = $this->action()->execute($this->context(['post_id' => 5]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('profile_id', $result->error);
    }

    public function test_passes_when_both_policies_pass(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $result = $this->action()->execute($this->context(['post_id' => 5, 'profile_id' => $profile->id()]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $this->dispatched);
    }

    public function test_merges_violations_from_content_policy(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));
        $this->contentPolicy->evaluateReturn = EditorialPolicyResult::violated(['Too short.']);

        $result = $this->action()->execute($this->context(['post_id' => 5, 'profile_id' => $profile->id()]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Too short.', $result->error);
        $this->assertCount(1, $this->dispatched);
    }

    public function test_merges_violations_from_research_policy(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(
            null,
            'news',
            'News',
            'standard_publish',
            'news',
            'manual',
            ['min_citation_count' => 5]
        ));
        $GLOBALS['__ana_test_postmeta'][5]['_ana_research_session_id'] = 9;
        $this->sessions->summarizeReturn = ResearchSummaryFixture::build(sessionId: 9, claims: [
            ['statement' => 'X.', 'citationTexts' => ['One.']],
        ]);

        $result = $this->action()->execute($this->context(['post_id' => 5, 'profile_id' => $profile->id()]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('citation', $result->error);
    }

    public function test_fails_when_profile_not_found(): void
    {
        $result = $this->action()->execute($this->context(['post_id' => 5, 'profile_id' => 999]));

        $this->assertTrue($result->isFailure());
    }
}

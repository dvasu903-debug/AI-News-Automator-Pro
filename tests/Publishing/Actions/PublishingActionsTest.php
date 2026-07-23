<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Actions;

use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Publishing\Actions\ArchiveAction;
use AINewsAutomator\Publishing\Actions\PublishDraftAction;
use AINewsAutomator\Publishing\Actions\ScheduleDraftAction;
use AINewsAutomator\Publishing\Actions\UnpublishAction;
use AINewsAutomator\Publishing\DTO\EditorialPolicyResult;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Events\PublishingRejectedEvent;
use AINewsAutomator\Tests\Publishing\Fakes\FakeEditorialPolicy;
use AINewsAutomator\Tests\Publishing\Fakes\FakePublisher;
use AINewsAutomator\Tests\Publishing\Fakes\InMemoryPublishingProfileRepository;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use PHPUnit\Framework\TestCase;

/**
 * Covers the four new publish/schedule/unpublish/archive Actions: their
 * stepConfig contract, the editorial-policy gate on the publish/schedule
 * paths (see ADR-0018 — no approval_mode check here, only content
 * policy), and correct ActionResult/PublishResult translation.
 */
final class PublishingActionsTest extends TestCase
{
    private FakePublisher $publisher;
    private FakeEditorialPolicy $policy;
    private InMemoryPublishingProfileRepository $profiles;
    private EventDispatcher $events;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->publisher = new FakePublisher();
        $this->policy = new FakeEditorialPolicy();
        $this->profiles = new InMemoryPublishingProfileRepository();
        $this->events = new EventDispatcher();
        $this->dispatched = [];

        $this->events->addListener(PublishingRejectedEvent::class, function (object $e): void {
            $this->dispatched[] = $e;
        });
    }

    private function context(array $stepConfig): WorkflowRunContext
    {
        return new WorkflowRunContext(1, 'publish', 'corr-1', $stepConfig, []);
    }

    private function metadataFactory(): EventMetadataFactory
    {
        return new EventMetadataFactory(new CorrelationContext('test'));
    }

    // --- PublishDraftAction ---

    public function test_publish_draft_action_requires_post_id(): void
    {
        $action = new PublishDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());

        $result = $action->execute($this->context(['profile_id' => 1]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('post_id', $result->error);
    }

    public function test_publish_draft_action_requires_profile_id(): void
    {
        $action = new PublishDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());

        $result = $action->execute($this->context(['post_id' => 5]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('profile_id', $result->error);
    }

    public function test_publish_draft_action_fails_when_profile_not_found(): void
    {
        $action = new PublishDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());

        $result = $action->execute($this->context(['post_id' => 5, 'profile_id' => 999]));

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $this->publisher->publishCalls);
    }

    public function test_publish_draft_action_rejects_when_editorial_policy_fails(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));
        $this->policy->evaluateReturn = EditorialPolicyResult::violated(['Too short.']);

        $action = new PublishDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());
        $result = $action->execute($this->context(['post_id' => 5, 'profile_id' => $profile->id()]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Too short.', $result->error);
        $this->assertSame([], $this->publisher->publishCalls, 'publish() must not be called on policy rejection.');
        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(PublishingRejectedEvent::class, $this->dispatched[0]);
    }

    public function test_publish_draft_action_publishes_when_policy_passes(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $action = new PublishDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());
        $result = $action->execute($this->context(['post_id' => 5, 'profile_id' => $profile->id()]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([5], $this->publisher->publishCalls);
        $this->assertSame(5, $result->output['post_id']);
    }

    // --- ScheduleDraftAction ---

    public function test_schedule_draft_action_requires_scheduled_for(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $action = new ScheduleDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());
        $result = $action->execute($this->context(['post_id' => 5, 'profile_id' => $profile->id()]));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('scheduled_for', $result->error);
    }

    public function test_schedule_draft_action_rejects_invalid_datetime(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $action = new ScheduleDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());
        $result = $action->execute($this->context([
            'post_id' => 5, 'profile_id' => $profile->id(), 'scheduled_for' => 'not-a-date',
        ]));

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $this->publisher->scheduleCalls);
    }

    public function test_schedule_draft_action_schedules_when_policy_passes(): void
    {
        $profile = $this->profiles->create(new PublishingProfile(null, 'news', 'News', 'standard_publish'));

        $action = new ScheduleDraftAction($this->publisher, $this->policy, $this->profiles, $this->events, $this->metadataFactory());
        $result = $action->execute($this->context([
            'post_id' => 5, 'profile_id' => $profile->id(), 'scheduled_for' => '2027-01-15 09:00:00',
        ]));

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $this->publisher->scheduleCalls);
        $this->assertSame(5, $this->publisher->scheduleCalls[0][0]);
    }

    // --- UnpublishAction / ArchiveAction ---

    public function test_unpublish_action_requires_post_id(): void
    {
        $action = new UnpublishAction($this->publisher);

        $result = $action->execute($this->context([]));

        $this->assertTrue($result->isFailure());
    }

    public function test_unpublish_action_delegates_to_publisher(): void
    {
        $action = new UnpublishAction($this->publisher);

        $result = $action->execute($this->context(['post_id' => 9]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([9], $this->publisher->unpublishCalls);
    }

    public function test_archive_action_delegates_to_publisher(): void
    {
        $action = new ArchiveAction($this->publisher);

        $result = $action->execute($this->context(['post_id' => 9]));

        $this->assertTrue($result->isSuccess());
        $this->assertSame([9], $this->publisher->archiveCalls);
    }
}

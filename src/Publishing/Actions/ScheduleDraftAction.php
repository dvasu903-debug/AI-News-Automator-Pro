<?php
/**
 * Workflow action: run the editorial policy check, then schedule a
 * draft for a future publish date. See PublishDraftAction's docblock
 * for the shared conventions this mirrors.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Actions;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\Events\PublishingRejectedEvent;
use AINewsAutomator\Publishing\Exceptions\DraftNotFoundException;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

final class ScheduleDraftAction implements ActionInterface
{
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly EditorialPolicyInterface $policy,
        private readonly PublishingProfileRepositoryInterface $profiles,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
    }

    public function type(): string
    {
        return 'publishing.schedule_draft';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $postId = (int) ($context->stepConfig['post_id'] ?? 0);
        $profileId = (int) ($context->stepConfig['profile_id'] ?? 0);
        $at = (string) ($context->stepConfig['scheduled_for'] ?? '');

        if ($postId <= 0) {
            return ActionResult::failure('publishing.schedule_draft requires a "post_id" in step config.');
        }

        if ($profileId <= 0) {
            return ActionResult::failure('publishing.schedule_draft requires a "profile_id" in step config.');
        }

        if ('' === $at) {
            return ActionResult::failure('publishing.schedule_draft requires a "scheduled_for" datetime string in step config.');
        }

        try {
            $scheduledFor = new \DateTimeImmutable($at);
        } catch (\Exception) {
            return ActionResult::failure(sprintf('"%s" is not a valid datetime for "scheduled_for".', $at));
        }

        $profile = $this->profiles->findById($profileId);

        if (null === $profile) {
            return ActionResult::failure(ProfileNotFoundException::forId($profileId)->getMessage());
        }

        try {
            $evaluation = $this->policy->evaluate($postId, $profile);
        } catch (DraftNotFoundException $e) {
            return ActionResult::failure($e->getMessage());
        }

        if (!$evaluation->passes()) {
            $this->events->dispatch(new PublishingRejectedEvent(
                $this->metadataFactory->create('Publishing', ['post_id' => $postId]),
                postId: $postId,
                reasons: $evaluation->violations,
            ));

            return ActionResult::failure(implode(' ', $evaluation->violations));
        }

        $result = $this->publisher->schedule($postId, $scheduledFor);

        return $result->isSuccess()
            ? ActionResult::success(['post_id' => $postId, 'scheduled_for' => $scheduledFor->format(DATE_ATOM)])
            : ActionResult::failure($result->error ?? 'Schedule failed for an unknown reason.');
    }
}

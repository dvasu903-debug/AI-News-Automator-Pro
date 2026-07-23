<?php
/**
 * Workflow action: run the editorial policy check, then publish a draft.
 * Reads "post_id" and "profile_id" from the step's config, mirroring how
 * every other Workflow action (NotificationAction, QueueJobAction, ...)
 * reads its own parameters from WorkflowRunContext::$stepConfig.
 *
 * Whether this step is reached at all under a manual-approval profile is
 * a workflow-DEFINITION concern (an approval_gate step placed before this
 * one) — see ADR-0018's "Planner" section. This action does not re-check
 * approval_mode; it only validates editorial policy before publishing.
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

final class PublishDraftAction implements ActionInterface
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
        return 'publishing.publish_draft';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $postId = (int) ($context->stepConfig['post_id'] ?? 0);
        $profileId = (int) ($context->stepConfig['profile_id'] ?? 0);

        if ($postId <= 0) {
            return ActionResult::failure('publishing.publish_draft requires a "post_id" in step config.');
        }

        if ($profileId <= 0) {
            return ActionResult::failure('publishing.publish_draft requires a "profile_id" in step config.');
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

        $result = $this->publisher->publish($postId);

        return $result->isSuccess()
            ? ActionResult::success(['post_id' => $postId])
            : ActionResult::failure($result->error ?? 'Publish failed for an unknown reason.');
    }
}

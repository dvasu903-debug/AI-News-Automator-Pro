<?php
/**
 * Workflow action: runs both the generic EditorialPolicyInterface
 * binding (DefaultEditorialPolicy — word count, AI disclosure) AND
 * ResearchEditorialPolicy (citation count, confidence, contradictions)
 * against a draft, merging violations from both (approved Decision 5).
 *
 * Reads "post_id" from step config if present, otherwise from the prior
 * "generate" step's output via WorkflowRunContext::priorOutput() — the
 * first real use of that previously-unused primitive (mirrors how
 * Milestone 3 activated ActionRegistryInterface). The literal-override
 * path keeps this action testable/usable standalone, the same way
 * PublishDraftAction's post_id can be a literal for a manually-triggered
 * run.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Actions;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\Events\PublishingRejectedEvent;
use AINewsAutomator\Publishing\Exceptions\DraftNotFoundException;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use AINewsAutomator\Publishing\Services\ResearchEditorialPolicy;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

final class ValidateContentAction implements ActionInterface
{
    private const DEFAULT_GENERATE_STEP_KEY = 'generate';

    public function __construct(
        private readonly EditorialPolicyInterface $contentPolicy,
        private readonly ResearchEditorialPolicy $researchPolicy,
        private readonly PublishingProfileRepositoryInterface $profiles,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
    }

    public function type(): string
    {
        return 'publishing.validate_content';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $generateStepKey = (string) ($context->stepConfig['generate_step_key'] ?? self::DEFAULT_GENERATE_STEP_KEY);
        $postId = (int) ($context->stepConfig['post_id'] ?? $context->priorOutput($generateStepKey, 'post_id', 0));
        $profileId = (int) ($context->stepConfig['profile_id'] ?? 0);

        if ($postId <= 0) {
            return ActionResult::failure('publishing.validate_content requires a resolvable "post_id" (step config or prior step output).');
        }

        if ($profileId <= 0) {
            return ActionResult::failure('publishing.validate_content requires a "profile_id" in step config.');
        }

        $profile = $this->profiles->findById($profileId);

        if (null === $profile) {
            return ActionResult::failure(ProfileNotFoundException::forId($profileId)->getMessage());
        }

        $violations = [];

        try {
            $violations = array_merge($violations, $this->contentPolicy->evaluate($postId, $profile)->violations);
        } catch (DraftNotFoundException $e) {
            return ActionResult::failure($e->getMessage());
        }

        try {
            $violations = array_merge($violations, $this->researchPolicy->evaluate($postId, $profile)->violations);
        } catch (SessionStateException $e) {
            return ActionResult::failure($e->getMessage());
        }

        if ([] !== $violations) {
            $this->events->dispatch(new PublishingRejectedEvent(
                $this->metadataFactory->create('Publishing', ['post_id' => $postId]),
                postId: $postId,
                reasons: $violations,
            ));

            return ActionResult::failure(implode(' ', $violations));
        }

        return ActionResult::success(['post_id' => $postId, 'profile_id' => $profileId]);
    }
}

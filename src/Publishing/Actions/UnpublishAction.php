<?php
/**
 * Workflow action: revert a published post to draft. No editorial
 * policy check — taking content down doesn't need a content-quality
 * gate the way publishing does.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Actions;

use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

final class UnpublishAction implements ActionInterface
{
    public function __construct(
        private readonly PublisherInterface $publisher,
    ) {
    }

    public function type(): string
    {
        return 'publishing.unpublish';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $postId = (int) ($context->stepConfig['post_id'] ?? 0);

        if ($postId <= 0) {
            return ActionResult::failure('publishing.unpublish requires a "post_id" in step config.');
        }

        $result = $this->publisher->unpublish($postId);

        return $result->isSuccess()
            ? ActionResult::success(['post_id' => $postId])
            : ActionResult::failure($result->error ?? 'Unpublish failed for an unknown reason.');
    }
}

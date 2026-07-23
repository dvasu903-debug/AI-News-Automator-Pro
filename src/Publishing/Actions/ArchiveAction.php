<?php
/**
 * Workflow action: archive a post (WordPress's native 'private' status —
 * see ADR-0018). No editorial policy check, for the same reason
 * UnpublishAction has none.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Actions;

use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

final class ArchiveAction implements ActionInterface
{
    public function __construct(
        private readonly PublisherInterface $publisher,
    ) {
    }

    public function type(): string
    {
        return 'publishing.archive';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $postId = (int) ($context->stepConfig['post_id'] ?? 0);

        if ($postId <= 0) {
            return ActionResult::failure('publishing.archive requires a "post_id" in step config.');
        }

        $result = $this->publisher->archive($postId);

        return $result->isSuccess()
            ? ActionResult::success(['post_id' => $postId])
            : ActionResult::failure($result->error ?? 'Archive failed for an unknown reason.');
    }
}

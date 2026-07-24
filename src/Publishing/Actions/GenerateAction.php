<?php
/**
 * Workflow action: generate draft content from a completed research
 * session and create the draft — no separate CreateDraftAction
 * (approved Decision 1). Does not implement RollbackableActionInterface,
 * matching every Milestone 3 action's precedent (no rollback of a
 * create); a later-step failure leaves an orphaned draft, the same
 * documented characteristic every other create-shaped action already
 * has.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Actions;

use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Publishing\Contracts\ContentGeneratorInterface;
use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Publishing\Events\DraftGeneratedEvent;
use AINewsAutomator\Publishing\Exceptions\ContentGenerationException;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;
use AINewsAutomator\Workflow\Entities\WorkflowStepErrorType;
use AINewsAutomator\Workflow\Retry\WorkflowStepException;

final class GenerateAction implements ActionInterface
{
    private const META_RESEARCH_SESSION_ID = '_ana_research_session_id';

    public function __construct(
        private readonly ContentGeneratorInterface $generator,
        private readonly SessionRepositoryInterface $sessions,
        private readonly DraftRepositoryInterface $drafts,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
    }

    public function type(): string
    {
        return 'publishing.generate';
    }

    /**
     * @throws WorkflowStepException When the underlying AI call or the
     *         content generator fails — classified so
     *         WorkflowStepRetryExecutor can tell a transient provider
     *         failure (retryable) from a configuration problem (not).
     */
    public function execute(WorkflowRunContext $context): ActionResult
    {
        $sessionId = (int) ($context->stepConfig['research_session_id'] ?? 0);

        if ($sessionId <= 0) {
            return ActionResult::failure('publishing.generate requires a "research_session_id" in step config.');
        }

        try {
            $summary = $this->sessions->summarize($sessionId);
        } catch (SessionStateException $e) {
            return ActionResult::failure($e->getMessage());
        }

        try {
            $content = $this->generator->generate($summary);
        } catch (ContentGenerationException $e) {
            // Missing/misconfigured template — retrying without human
            // intervention cannot fix this.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e/its message build an internal WorkflowStepException message only, never echoed as HTML.
            throw new WorkflowStepException($e->getMessage(), WorkflowStepErrorType::Validation, $e);
        } catch (AIException $e) {
            // Delegate to AIException's own classification rather than
            // duplicating its match logic — see ADR-0019 decision notes.
            $errorType = $e->isRetryable() ? WorkflowStepErrorType::Transient : WorkflowStepErrorType::Validation;
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e/its message build an internal WorkflowStepException message only, never echoed as HTML.
            throw new WorkflowStepException($e->getMessage(), $errorType, $e);
        }

        $postId = $this->drafts->create($content->title, $content->body, [
            self::META_RESEARCH_SESSION_ID => $sessionId,
        ]);

        $this->events->dispatch(new DraftGeneratedEvent(
            $this->metadataFactory->create('Publishing', ['post_id' => $postId]),
            postId: $postId,
            researchSessionId: $sessionId,
        ));

        return ActionResult::success(['post_id' => $postId]);
    }
}

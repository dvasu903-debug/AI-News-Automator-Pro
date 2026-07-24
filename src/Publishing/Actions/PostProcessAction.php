<?php
/**
 * Workflow action: populates the previously-unused ana_draft_seo table
 * (Migration_20260722100003, Milestone 1) for a generated draft.
 * Scoped to SEO metadata only (approved Decision 6) — social sharing
 * and analytics remain deferred, mirroring ADR-0018's own deferral
 * precedent.
 *
 * Every derivation here is strictly deterministic string manipulation
 * on the post's own title/content — never a second AIManager::chat()
 * call, which would reopen the same untrusted-output trust boundary
 * AiContentGenerator already closes, with no sanitization plan of its
 * own.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Actions;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Publishing\Events\PublishingCompletedEvent;
use AINewsAutomator\Publishing\Exceptions\DraftNotFoundException;
use AINewsAutomator\Workflow\Contracts\ActionInterface;
use AINewsAutomator\Workflow\DTO\ActionResult;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

final class PostProcessAction implements ActionInterface
{
    private const DEFAULT_GENERATE_STEP_KEY = 'generate';
    private const DEFAULT_ROBOTS_DIRECTIVES = 'index,follow';
    private const META_TITLE_MAX_LENGTH = 60;
    private const META_DESCRIPTION_MAX_LENGTH = 155;

    public function __construct(
        private readonly DraftSeoRepositoryInterface $seo,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
    }

    public function type(): string
    {
        return 'publishing.post_process';
    }

    public function execute(WorkflowRunContext $context): ActionResult
    {
        $generateStepKey = (string) ($context->stepConfig['generate_step_key'] ?? self::DEFAULT_GENERATE_STEP_KEY);
        $postId = (int) ($context->stepConfig['post_id'] ?? $context->priorOutput($generateStepKey, 'post_id', 0));

        if ($postId <= 0) {
            return ActionResult::failure('publishing.post_process requires a resolvable "post_id" (step config or prior step output).');
        }

        $post = get_post($postId);

        if (null === $post) {
            return ActionResult::failure(DraftNotFoundException::forId($postId)->getMessage());
        }

        $title = (string) $post->post_title;
        $content = (string) $post->post_content;

        $this->seo->upsert(new DraftSeo(
            id: null,
            postId: $postId,
            metaTitle: $this->deriveMetaTitle($title),
            metaDescription: $this->deriveMetaDescription($content),
            focusKeyword: $this->deriveFocusKeyword($title),
            // No permalink source is integrated this milestone — left
            // null rather than guessed at, so a later SEO module can
            // populate it deliberately.
            canonicalUrl: null,
            robotsDirectives: self::DEFAULT_ROBOTS_DIRECTIVES,
        ));

        $this->events->dispatch(new PublishingCompletedEvent(
            $this->metadataFactory->create('Publishing', ['post_id' => $postId]),
            postId: $postId,
        ));

        return ActionResult::success(['post_id' => $postId]);
    }

    private function deriveMetaTitle(string $title): string
    {
        return mb_substr(trim(wp_strip_all_tags($title)), 0, self::META_TITLE_MAX_LENGTH);
    }

    private function deriveMetaDescription(string $content): string
    {
        $stripped = trim(wp_strip_all_tags($content));

        if (mb_strlen($stripped) <= self::META_DESCRIPTION_MAX_LENGTH) {
            return $stripped;
        }

        $truncated = mb_substr($stripped, 0, self::META_DESCRIPTION_MAX_LENGTH);
        $lastSpace = mb_strrpos($truncated, ' ');

        if (false !== $lastSpace) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '…';
    }

    /**
     * Heuristic only (longest word in the title) — not a stopword-aware
     * keyword extraction algorithm, which is out of scope for this
     * milestone's deterministic-derivation requirement.
     */
    private function deriveFocusKeyword(string $title): ?string
    {
        $words = preg_split('/\s+/', trim(wp_strip_all_tags($title)), -1, PREG_SPLIT_NO_EMPTY);

        if (!$words) {
            return null;
        }

        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return mb_strtolower($words[0]);
    }
}

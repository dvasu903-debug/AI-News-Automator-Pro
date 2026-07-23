<?php
/**
 * Direct, manual publishing state transitions — see PublisherInterface's
 * docblock and ADR-0018. These methods are profile-agnostic mechanical
 * transitions; profile-aware policy (editorial checks, approval_mode
 * gating) lives one layer up, in the Actions that call this service
 * (see ADR-0018's "Planner" section).
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Services;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Publishing\DTO\PublishResult;
use AINewsAutomator\Publishing\Events\ArticleArchivedEvent;
use AINewsAutomator\Publishing\Events\ArticlePublishedEvent;
use AINewsAutomator\Publishing\Events\ArticleScheduledEvent;
use AINewsAutomator\Publishing\Events\ArticleUnpublishedEvent;
use AINewsAutomator\Publishing\Events\PublishingFailedEvent;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;

final class PublishingService implements PublisherInterface
{
    private const SOURCE_MODULE = 'Publishing';

    public function __construct(
        private readonly ArticleRepositoryInterface $articles,
        private readonly DraftRepositoryInterface $drafts,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
    }

    public function publish(int $postId): PublishResult
    {
        // approve() only transitions AI-generated drafts (it no-ops on a
        // manually-created one) — see ADR-0018. Branch so a manual draft
        // still publishes correctly.
        if ($this->drafts->isGenerated($postId)) {
            if (!$this->articles->approve($postId)) {
                return $this->fail($postId, 'Post could not be approved for publishing.');
            }
        } else {
            $result = wp_update_post([
                'ID'          => $postId,
                'post_status' => 'publish',
            ]);

            if (is_wp_error($result) || 0 === $result) {
                return $this->fail($postId, $this->wpErrorMessage($result, 'wp_update_post() returned 0.'));
            }
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->events->dispatch(new ArticlePublishedEvent(
            $this->metadataFactory->create(self::SOURCE_MODULE, ['post_id' => $postId]),
            postId: $postId,
        ));

        return PublishResult::published($postId, $now);
    }

    public function schedule(int $postId, \DateTimeImmutable $at): PublishResult
    {
        $result = wp_update_post([
            'ID'          => $postId,
            'post_status' => 'future',
            'post_date'   => $at->format('Y-m-d H:i:s'),
        ]);

        if (is_wp_error($result) || 0 === $result) {
            return $this->fail($postId, $this->wpErrorMessage($result, 'wp_update_post() returned 0.'));
        }

        $this->events->dispatch(new ArticleScheduledEvent(
            $this->metadataFactory->create(self::SOURCE_MODULE, ['post_id' => $postId]),
            postId: $postId,
            scheduledFor: $at,
        ));

        return PublishResult::scheduled($postId, $at);
    }

    public function unpublish(int $postId): PublishResult
    {
        $result = wp_update_post([
            'ID'          => $postId,
            'post_status' => 'draft',
        ]);

        if (is_wp_error($result) || 0 === $result) {
            return $this->fail($postId, $this->wpErrorMessage($result, 'wp_update_post() returned 0.'));
        }

        $this->events->dispatch(new ArticleUnpublishedEvent(
            $this->metadataFactory->create(self::SOURCE_MODULE, ['post_id' => $postId]),
            postId: $postId,
        ));

        return PublishResult::unpublished($postId);
    }

    public function archive(int $postId): PublishResult
    {
        // WordPress core has no "archived" post_status; 'private' (visible
        // to users with read_private_posts, excluded from public queries/
        // feeds) is the closest native fit — see ADR-0018.
        $result = wp_update_post([
            'ID'          => $postId,
            'post_status' => 'private',
        ]);

        if (is_wp_error($result) || 0 === $result) {
            return $this->fail($postId, $this->wpErrorMessage($result, 'wp_update_post() returned 0.'));
        }

        $this->events->dispatch(new ArticleArchivedEvent(
            $this->metadataFactory->create(self::SOURCE_MODULE, ['post_id' => $postId]),
            postId: $postId,
        ));

        return PublishResult::archived($postId);
    }

    private function fail(int $postId, string $error): PublishResult
    {
        $this->events->dispatch(new PublishingFailedEvent(
            $this->metadataFactory->create(self::SOURCE_MODULE, ['post_id' => $postId]),
            postId: $postId,
            error: $error,
        ));

        return PublishResult::failed($postId, $error);
    }

    private function wpErrorMessage(mixed $result, string $default): string
    {
        return is_wp_error($result) ? $result->get_error_message() : $default;
    }
}

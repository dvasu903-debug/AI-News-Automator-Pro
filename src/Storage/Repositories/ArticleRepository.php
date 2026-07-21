<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;
use AINewsAutomator\Storage\Events\ArticleApprovedEvent;
use AINewsAutomator\Storage\Events\ArticleDraftCreatedEvent;
use AINewsAutomator\Storage\Exceptions\RepositoryException;
use AINewsAutomator\Storage\Exceptions\ValidationException;

/**
 * Wraps wp_insert_post/wp_update_post/get_posts plus the plugin's own
 * postmeta — deliberately introduces NO new table (see module README:
 * articles are WordPress posts, duplicating them would create two sources
 * of truth and break WordPress-native features like revisions and the
 * editor). The `_ana_generated` meta key matches what earlier modules
 * (the original v1.1 plugin build) already used, for continuity.
 */
final class ArticleRepository implements ArticleRepositoryInterface
{
    private const META_GENERATED   = '_ana_generated';
    private const META_SOURCE_URL  = '_ana_source_url';

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
    ) {
    }

    public function createDraft(string $title, string $content, array $meta = []): int
    {
        $this->validateDraft($title, $content);

        $postId = wp_insert_post([
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ], true);

        if (is_wp_error($postId)) {
            throw new RepositoryException('ArticleRepository: failed to create draft — ' . $postId->get_error_message());
        }

        $postId = (int) $postId;

        update_post_meta($postId, self::META_GENERATED, 1);

        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }

        $this->events->dispatch(new ArticleDraftCreatedEvent(
            $this->metadataFactory->create('Storage', ['post_id' => $postId]),
            postId: $postId,
            sourceUrl: isset($meta[self::META_SOURCE_URL]) ? (string) $meta[self::META_SOURCE_URL] : null,
        ));

        return $postId;
    }

    public function approve(int $postId): bool
    {
        if (!$this->isGenerated($postId)) {
            return false;
        }

        $result = wp_update_post(['ID' => $postId, 'post_status' => 'publish'], true);

        if (is_wp_error($result)) {
            return false;
        }

        $this->events->dispatch(new ArticleApprovedEvent(
            $this->metadataFactory->create('Storage', ['post_id' => $postId]),
            postId: $postId,
            approvedByUserId: get_current_user_id(),
        ));

        return true;
    }

    public function pendingReview(): array
    {
        $posts = get_posts([
            'post_type'   => 'post',
            'post_status' => 'draft',
            'meta_key'    => self::META_GENERATED,
            'meta_value'  => 1,
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        return array_map('intval', $posts);
    }

    public function bySourceUrl(string $url): ?int
    {
        $posts = get_posts([
            'post_type'   => 'post',
            'post_status' => 'any',
            'meta_key'    => self::META_SOURCE_URL,
            'meta_value'  => $url,
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);

        return $posts !== [] ? (int) $posts[0] : null;
    }

    public function isGenerated(int $postId): bool
    {
        return (bool) get_post_meta($postId, self::META_GENERATED, true);
    }

    private function validateDraft(string $title, string $content): void
    {
        $errors = [];

        if (trim($title) === '') {
            $errors['title'] = 'Title is required.';
        }

        if (trim($content) === '') {
            $errors['content'] = 'Content is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Article draft failed validation.');
        }
    }
}

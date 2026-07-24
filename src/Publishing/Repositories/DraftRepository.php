<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Repositories;

use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;

/**
 * Composes ArticleRepositoryInterface (frozen, Module 3) — approved
 * Decision 1. createDraft/bySourceUrl/isGenerated delegate straight
 * through; update/delete use WordPress's own post functions directly,
 * since ArticleRepositoryInterface deliberately doesn't expose them
 * (its own scope is creation + the review-queue read path). This is
 * exactly the kind of draft-specific behavior Decision 1 argued for
 * keeping out of the frozen Article contract.
 */
final class DraftRepository implements DraftRepositoryInterface
{
    public function __construct(private readonly ArticleRepositoryInterface $articles)
    {
    }

    public function create(string $title, string $content, array $meta = []): int
    {
        return $this->articles->createDraft($title, $content, $meta);
    }

    public function update(int $postId, ?string $title = null, ?string $content = null, array $meta = []): void
    {
        $postData = ['ID' => $postId];

        if ($title !== null) {
            $postData['post_title'] = $title;
        }

        if ($content !== null) {
            $postData['post_content'] = $content;
        }

        if (count($postData) > 1) {
            wp_update_post($postData);
        }

        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }
    }

    public function delete(int $postId): void
    {
        wp_delete_post($postId, true);
    }

    public function findBySourceUrl(string $url): ?int
    {
        return $this->articles->bySourceUrl($url);
    }

    public function isGenerated(int $postId): bool
    {
        return $this->articles->isGenerated($postId);
    }
}

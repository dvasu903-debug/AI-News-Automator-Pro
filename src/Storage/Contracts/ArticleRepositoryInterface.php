<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Wraps WP_Post + the plugin's own postmeta for AI-generated articles.
 * Deliberately does NOT introduce a parallel ana_articles table — see
 * module README for why articles stay on WordPress's native storage.
 */
interface ArticleRepositoryInterface
{
    /**
     * @param array<string, mixed> $meta Arbitrary plugin postmeta, e.g. ['_ana_confidence' => 0.9].
     * @return int The created post id.
     */
    public function createDraft(string $title, string $content, array $meta = []): int;

    public function approve(int $postId): bool;

    /**
     * @return list<int> Post ids of AI-generated drafts awaiting review.
     */
    public function pendingReview(): array;

    public function bySourceUrl(string $url): ?int;

    public function isGenerated(int $postId): bool;
}

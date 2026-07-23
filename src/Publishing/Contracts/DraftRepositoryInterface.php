<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

/**
 * Composes Storage\Contracts\ArticleRepositoryInterface (frozen, Module
 * 3) rather than extending it — approved Decision 1. Keeps the frozen
 * contract untouched and separates draft/editorial concerns (status
 * transitions, scheduling) from generic article persistence, which
 * other future content types (pages, products, custom post types)
 * would not need to inherit.
 */
interface DraftRepositoryInterface
{
    /**
     * @param array<string, mixed> $meta Arbitrary plugin postmeta.
     * @return int The created post id.
     */
    public function create(string $title, string $content, array $meta = []): int;

    /**
     * @param array<string, mixed> $meta Postmeta to merge (not replace).
     */
    public function update(int $postId, ?string $title = null, ?string $content = null, array $meta = []): void;

    public function delete(int $postId): void;

    public function findBySourceUrl(string $url): ?int;

    public function isGenerated(int $postId): bool;
}

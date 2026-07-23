<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\DTO;

use DateTimeImmutable;

/**
 * Immutable value object matching ana_draft_seo's schema
 * (Migration_20260722100003_CreateDraftSeoTable): id, post_id (UNIQUE),
 * meta_title, meta_description, focus_keyword, canonical_url,
 * robots_directives, created_at, updated_at.
 */
final class DraftSeo
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $postId,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly ?string $focusKeyword,
        public readonly ?string $canonicalUrl,
        public readonly ?string $robotsDirectives,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->postId,
            $this->metaTitle,
            $this->metaDescription,
            $this->focusKeyword,
            $this->canonicalUrl,
            $this->robotsDirectives,
            $this->createdAt,
            $this->updatedAt
        );
    }

    public function withTimestamps(DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt): self
    {
        return new self(
            $this->id,
            $this->postId,
            $this->metaTitle,
            $this->metaDescription,
            $this->focusKeyword,
            $this->canonicalUrl,
            $this->robotsDirectives,
            $createdAt,
            $updatedAt
        );
    }
}

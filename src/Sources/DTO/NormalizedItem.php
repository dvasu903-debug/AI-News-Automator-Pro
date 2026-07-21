<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\DTO;

/**
 * The provider-agnostic shape every connector normalizes its output to —
 * RSS <item>, a JSON API's article object, and a crawled page's extracted
 * link all become this same DTO, regardless of which connector produced
 * them. Downstream consumption (dedup, validation, event emission) stays
 * connector-agnostic because of this, mirroring how AI's ChatResponse
 * stays provider-agnostic regardless of which vendor produced it.
 */
final class NormalizedItem
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?\DateTimeImmutable $publishedAt = null,
        public readonly ?string $summary = null,
        public readonly ?string $author = null,
        public readonly ?string $guid = null,
    ) {
    }

    /**
     * A stable fingerprint for deduplication: prefers the feed-supplied
     * GUID (the most reliable identifier when present — a feed's URL for
     * an item can change on republish, but a GUID is meant not to), falls
     * back to the URL when no GUID was supplied.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->guid ?? $this->url);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Seo\DTO;

/**
 * The complete set of SEO tag data for one post, assembled by
 * MetaTagBuilder. Pure data — no escaping, no rendering. SeoHeadRenderer
 * is the only class that turns this into echoed HTML, escaping every
 * field at its own output context.
 */
final class SeoTagData
{
    /**
     * @param array<string, string> $openGraph Open Graph property => content, e.g. 'og:title' => '...'.
     * @param array<string, string> $twitterCard Twitter Card property => content, e.g. 'twitter:card' => 'summary'.
     * @param array<string, mixed> $jsonLd schema.org JSON-LD payload (already a plain array, not yet encoded).
     */
    public function __construct(
        public readonly string $canonicalUrl,
        public readonly ?string $robotsDirectives,
        public readonly array $openGraph,
        public readonly array $twitterCard,
        public readonly array $jsonLd,
    ) {
    }
}

<?php
/**
 * Builds a schema.org NewsArticle JSON-LD payload as a plain array —
 * never encodes it, never echoes it. SeoHeadRenderer is the only place
 * this payload is turned into a <script type="application/ld+json">
 * block, via wp_json_encode() at the actual point of output.
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Services;

use AINewsAutomator\Publishing\DTO\DraftSeo;

final class SchemaOrgGenerator
{
    /**
     * @return array<string, mixed>
     */
    public function generate(\WP_Post $post, DraftSeo $seo, string $canonicalUrl, ?string $imageUrl): array
    {
        $siteName = get_bloginfo('name');

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $seo->metaTitle ?? wp_strip_all_tags($post->post_title),
            'datePublished' => $this->toIso8601($post->post_date),
            'dateModified' => $this->toIso8601($post->post_modified ?: $post->post_date),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonicalUrl,
            ],
            'author' => [
                '@type' => 'Organization',
                'name' => $siteName,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => $siteName,
            ],
        ];

        if (null !== $seo->metaDescription && '' !== $seo->metaDescription) {
            $jsonLd['description'] = $seo->metaDescription;
        }

        if (null !== $imageUrl) {
            $jsonLd['image'] = [$imageUrl];
        }

        return $jsonLd;
    }

    private function toIso8601(string $mysqlDateTime): string
    {
        // Matches Storage\Entities\EntityDates::fromMysql()'s own
        // convention: parse the naive datetime string as-is, no forced
        // timezone override.
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDateTime);

        return false !== $date ? $date->format(\DATE_ATOM) : '';
    }
}

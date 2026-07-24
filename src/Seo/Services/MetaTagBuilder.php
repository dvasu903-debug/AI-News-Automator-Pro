<?php
/**
 * Assembles a post's complete SeoTagData — the one place tag
 * *construction* happens in this module. No escaping, no echoing, no
 * WordPress hooks; SeoHeadRenderer is solely responsible for rendering
 * whatever this class returns. Returns null whenever there is nothing
 * to render (no linked ana_draft_seo row, or no resolvable canonical
 * URL), which SeoHeadRenderer treats as "render nothing."
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Services;

use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Seo\DTO\SeoTagData;

final class MetaTagBuilder
{
    public function __construct(
        private readonly DraftSeoRepositoryInterface $seoRepository,
        private readonly SchemaOrgGenerator $schemaGenerator,
        private readonly CanonicalUrlResolver $canonicalResolver,
    ) {
    }

    public function build(int $postId): ?SeoTagData
    {
        $seo = $this->seoRepository->findByPostId($postId);

        if (null === $seo) {
            return null;
        }

        $post = get_post($postId);

        if (null === $post) {
            return null;
        }

        $canonicalUrl = $this->canonicalResolver->resolve($postId);

        if (null === $canonicalUrl) {
            return null;
        }

        $title = (null !== $seo->metaTitle && '' !== $seo->metaTitle) ? $seo->metaTitle : wp_strip_all_tags($post->post_title);
        $description = $seo->metaDescription ?? '';
        $imageUrl = get_the_post_thumbnail_url($postId, 'large');
        $imageUrl = is_string($imageUrl) ? $imageUrl : null;
        $siteName = get_bloginfo('name');

        $openGraph = [
            'og:title' => $title,
            'og:description' => $description,
            'og:type' => 'article',
            'og:url' => $canonicalUrl,
            'og:site_name' => $siteName,
        ];

        $twitterCard = [
            'twitter:card' => null !== $imageUrl ? 'summary_large_image' : 'summary',
            'twitter:title' => $title,
            'twitter:description' => $description,
        ];

        if (null !== $imageUrl) {
            $openGraph['og:image'] = $imageUrl;
            $twitterCard['twitter:image'] = $imageUrl;
        }

        return new SeoTagData(
            canonicalUrl: $canonicalUrl,
            robotsDirectives: $seo->robotsDirectives,
            openGraph: $openGraph,
            twitterCard: $twitterCard,
            jsonLd: $this->schemaGenerator->generate($post, $seo, $canonicalUrl, $imageUrl),
        );
    }
}

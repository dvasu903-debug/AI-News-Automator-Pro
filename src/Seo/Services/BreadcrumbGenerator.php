<?php
/**
 * Deterministic breadcrumb trail: Home -> primary category (if any) ->
 * post title. Built entirely from WordPress's own taxonomy/permalink
 * data — no plugin-specific dependency.
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Services;

final class BreadcrumbGenerator
{
    /**
     * @return list<array{label: string, url: string}>
     */
    public function generate(int $postId): array
    {
        $breadcrumbs = [
            ['label' => get_bloginfo('name'), 'url' => home_url('/')],
        ];

        $categories = get_the_category($postId);

        if ([] !== $categories) {
            $primary = $categories[0];
            $link = get_category_link($primary->term_id);

            if (is_string($link)) {
                $breadcrumbs[] = ['label' => $primary->name, 'url' => $link];
            }
        }

        $post = get_post($postId);

        if (null !== $post) {
            $permalink = get_permalink($postId);
            $breadcrumbs[] = [
                'label' => wp_strip_all_tags($post->post_title),
                'url' => is_string($permalink) ? $permalink : '',
            ];
        }

        return $breadcrumbs;
    }
}

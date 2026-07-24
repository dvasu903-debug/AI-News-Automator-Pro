<?php
/**
 * Wraps get_permalink() — the single, live, always-current source of a
 * post's canonical URL. Deliberately never reads ana_draft_seo's own
 * canonical_url column: a stored snapshot goes stale the moment a
 * slug/permalink structure changes, and WordPress's own get_permalink()
 * is already the authoritative source. See Module 9 design doc, Open
 * Question 2.
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Services;

final class CanonicalUrlResolver
{
    public function resolve(int $postId): ?string
    {
        $url = get_permalink($postId);

        return is_string($url) && '' !== $url ? $url : null;
    }
}

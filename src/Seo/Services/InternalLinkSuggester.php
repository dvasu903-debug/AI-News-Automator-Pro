<?php
/**
 * Deterministic internal-link suggestions: for a post whose draft is
 * linked to a research session, ranks other PUBLISHED posts by how
 * many extracted entities they share with it. No AI call anywhere in
 * this path — matches ADR-0019 decision 6's reasoning for why
 * PostProcessAction never makes a second AI call, applied here for the
 * same reason (avoid reopening an untrusted-output trust boundary with
 * no sanitization plan of its own).
 *
 * Admin-editor-only by design — never invoked from SeoHeadRenderer's
 * public wp_head path (see the module design doc's Performance
 * section).
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Services;

use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Seo\Contracts\InternalLinkSuggesterInterface;

final class InternalLinkSuggester implements InternalLinkSuggesterInterface
{
    private const META_RESEARCH_SESSION_ID = '_ana_research_session_id';

    public function __construct(private readonly SessionRepositoryInterface $sessions)
    {
    }

    public function suggestFor(int $postId, int $limit = 5): array
    {
        $sessionId = (int) get_post_meta($postId, self::META_RESEARCH_SESSION_ID, true);

        if ($sessionId <= 0) {
            return [];
        }

        $entityNames = $this->entityNamesFor($sessionId);

        if ([] === $entityNames) {
            return [];
        }

        $candidates = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_key' => self::META_RESEARCH_SESSION_ID,
            'numberposts' => -1,
            'exclude' => [$postId],
            'fields' => 'ids',
        ]);

        $ranked = [];

        foreach ($candidates as $candidatePostId) {
            $candidatePostId = (int) $candidatePostId;
            $candidateSessionId = (int) get_post_meta($candidatePostId, self::META_RESEARCH_SESSION_ID, true);

            if ($candidateSessionId <= 0 || $candidateSessionId === $sessionId) {
                continue;
            }

            $candidateEntityNames = $this->entityNamesFor($candidateSessionId);
            $shared = count(array_intersect($entityNames, $candidateEntityNames));

            if ($shared === 0) {
                continue;
            }

            $post = get_post($candidatePostId);

            $ranked[] = [
                'postId' => $candidatePostId,
                'title' => null !== $post ? (string) $post->post_title : '',
                'sharedEntityCount' => $shared,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['sharedEntityCount'] <=> $a['sharedEntityCount']);

        return array_slice($ranked, 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function entityNamesFor(int $sessionId): array
    {
        try {
            $summary = $this->sessions->summarize($sessionId);
        } catch (SessionStateException) {
            return [];
        }

        return array_map(
            static fn ($entity): string => mb_strtolower($entity->name),
            $summary->entities
        );
    }
}

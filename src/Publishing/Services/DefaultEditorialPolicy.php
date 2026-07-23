<?php
/**
 * Default EditorialPolicyInterface implementation — see that interface's
 * docblock and ADR-0018 for scope (word-count bounds and AI-generation
 * disclosure only; citation/confidence checks are deferred pending
 * Research\DTO\ResearchSummary integration).
 *
 * Reads the post directly via get_post() rather than through
 * DraftRepositoryInterface, mirroring the precedent
 * Publishing\Repositories\DraftRepository already set: that
 * interface's scope is deliberately narrow (create/update/delete +
 * review-queue reads), not a general post accessor.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Services;

use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\DTO\EditorialPolicyResult;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\DraftNotFoundException;

final class DefaultEditorialPolicy implements EditorialPolicyInterface
{
    public function __construct(
        private readonly DraftRepositoryInterface $drafts,
    ) {
    }

    public function evaluate(int $postId, PublishingProfile $profile): EditorialPolicyResult
    {
        $post = get_post($postId);

        if (null === $post) {
            throw DraftNotFoundException::forId($postId);
        }

        $violations = [];

        $this->checkDisclosure($postId, $profile, $violations);
        $this->checkWordCount($post, $profile, $violations);

        return $violations === []
            ? EditorialPolicyResult::passed()
            : EditorialPolicyResult::violated($violations);
    }

    /**
     * @param list<string> $violations
     */
    private function checkDisclosure(int $postId, PublishingProfile $profile, array &$violations): void
    {
        if (!$this->drafts->isGenerated($postId)) {
            return;
        }

        if (true !== $profile->configValue('ai_disclosure_acknowledged', false)) {
            $violations[] = 'AI-generated content requires the publishing profile to acknowledge '
                . 'disclosure (config key "ai_disclosure_acknowledged" must be true).';
        }
    }

    /**
     * @param list<string> $violations
     */
    private function checkWordCount(\WP_Post $post, PublishingProfile $profile, array &$violations): void
    {
        $minWords = $profile->configValue('min_word_count');
        $maxWords = $profile->configValue('max_word_count');

        if ($minWords === null && $maxWords === null) {
            return;
        }

        $wordCount = str_word_count(wp_strip_all_tags((string) $post->post_content));

        if (is_int($minWords) && $wordCount < $minWords) {
            $violations[] = sprintf(
                'Content has %d word(s), below the profile\'s minimum of %d.',
                $wordCount,
                $minWords
            );
        }

        if (is_int($maxWords) && $wordCount > $maxWords) {
            $violations[] = sprintf(
                'Content has %d word(s), above the profile\'s maximum of %d.',
                $wordCount,
                $maxWords
            );
        }
    }
}

<?php
/**
 * A second EditorialPolicyInterface implementation (approved Decision
 * 5), not a new interface — this directly follows what ADR-0018's own
 * Consequences section anticipated: "extends DefaultEditorialPolicy (or
 * adds a second policy implementation)... since evaluate() already
 * returns a violations list open to more checks." Zero diff to any
 * frozen Milestone 3 file.
 *
 * Bound as its own concrete-class container entry — NOT swapping the
 * existing EditorialPolicyInterface::class binding, which stays
 * DefaultEditorialPolicy so PublishDraftAction/ScheduleDraftAction's
 * Milestone 3 behavior is unchanged. ValidateContentAction (this
 * milestone) is the one caller that injects both.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Services;

use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\DTO\EditorialPolicyResult;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;

final class ResearchEditorialPolicy implements EditorialPolicyInterface
{
    private const META_RESEARCH_SESSION_ID = '_ana_research_session_id';

    public function __construct(private readonly SessionRepositoryInterface $sessions)
    {
    }

    /**
     * @throws \AINewsAutomator\Research\Exceptions\SessionStateException If the linked session is not yet Completed.
     */
    public function evaluate(int $postId, PublishingProfile $profile): EditorialPolicyResult
    {
        $sessionId = (int) get_post_meta($postId, self::META_RESEARCH_SESSION_ID, true);

        if ($sessionId <= 0) {
            // No research session tied to this draft (e.g. a manually
            // created one) — nothing to validate against, passes
            // trivially rather than penalizing non-AI-assisted drafts.
            return EditorialPolicyResult::passed();
        }

        $summary = $this->sessions->summarize($sessionId);
        $violations = [];

        $minCitations = $profile->configValue('min_citation_count');
        if (is_int($minCitations) && $summary->citationCount() < $minCitations) {
            $violations[] = sprintf(
                'Research backing has %d citation(s), below the profile\'s minimum of %d.',
                $summary->citationCount(),
                $minCitations
            );
        }

        $minConfidence = $profile->configValue('min_confidence');
        if ((is_float($minConfidence) || is_int($minConfidence)) && $summary->overallConfidence < (float) $minConfidence) {
            $violations[] = sprintf(
                'Research overall confidence %.2f is below the profile\'s minimum of %.2f.',
                $summary->overallConfidence,
                (float) $minConfidence
            );
        }

        if ($summary->hasBlockingContradictions()) {
            $violations[] = 'Research contains unresolved contradictions that block publishing.';
        }

        return [] === $violations
            ? EditorialPolicyResult::passed()
            : EditorialPolicyResult::violated($violations);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

use AINewsAutomator\Publishing\DTO\EditorialPolicyResult;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\DraftNotFoundException;

/**
 * "Trust before speed" made concrete: a publish/schedule request is
 * evaluated against the target PublishingProfile's configured policy
 * before PublishingService lets it proceed. Returns a result listing
 * every violation rather than throwing on the first one, so a caller
 * (e.g. a REST response) can show an editor everything that needs
 * fixing at once — same shape as ProfileValidationException's
 * error-collection discipline (Milestone 2).
 *
 * Scope note (see ADR-0018): citation-count and Research-confidence
 * checks described in the Module 8 design doc require
 * Research\DTO\ResearchSummary, which nothing in Publishing consumes
 * yet — that integration is deferred to a future milestone. This
 * interface only covers what's checkable against a draft post and its
 * PublishingProfile today (AI-generation disclosure, word-count
 * bounds), and is intentionally shaped so more checks can be added
 * later without a breaking change.
 */
interface EditorialPolicyInterface
{
    /**
     * @throws DraftNotFoundException When $postId is not a known post.
     */
    public function evaluate(int $postId, PublishingProfile $profile): EditorialPolicyResult;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

use AINewsAutomator\Publishing\DTO\DraftSeo;

/**
 * Persists structured SEO metadata into ana_draft_seo
 * (Migration_20260722100003_CreateDraftSeoTable, Milestone 1 — unused
 * until this milestone's PostProcessAction).
 */
interface DraftSeoRepositoryInterface
{
    /**
     * Insert-or-replace by the table's UNIQUE post_id key. PostProcessAction
     * may run more than once for the same draft (workflow retry, manual
     * re-run); SEO metadata is derived output, not user-entered data, so
     * overwriting on a repeat run is correct rather than erroring on a
     * duplicate key.
     */
    public function upsert(DraftSeo $seo): DraftSeo;

    public function findByPostId(int $postId): ?DraftSeo;
}

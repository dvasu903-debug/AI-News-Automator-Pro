<?php

declare(strict_types=1);

namespace AINewsAutomator\Seo\Contracts;

/**
 * Deterministic internal-link suggestions for the post editing screen
 * — never on the public wp_head render path (admin-editor-only, per
 * this module's design). Ranking is by shared research-entity count,
 * never an AI call, for the same untrusted-output-boundary reasoning
 * ADR-0019 established for Publishing's PostProcessAction.
 */
interface InternalLinkSuggesterInterface
{
    /**
     * @return list<array{postId: int, title: string, sharedEntityCount: int}>
     */
    public function suggestFor(int $postId, int $limit = 5): array;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Seo\Contracts;

use AINewsAutomator\Seo\DTO\SeoTagData;

/**
 * The future-extensibility seam for vertical- or integration-specific
 * SEO behavior (e.g. a future Google Discover, News SEO, or WooCommerce
 * SEO provider), approved alongside this module's design. Exactly one
 * implementation exists today — Services\DefaultSeoProvider, bound
 * directly to this interface — mirroring EditorialPolicyInterface's own
 * single-implementation starting state in Module 8 before
 * ResearchEditorialPolicy existed. No registry/discovery mechanism is
 * built until a second implementation is genuinely needed.
 */
interface SeoProviderInterface
{
    /**
     * Returns null when this provider has nothing to contribute for
     * $postId (e.g. no linked ana_draft_seo row) — SeoHeadRenderer
     * renders nothing in that case rather than partial/empty tags.
     */
    public function provide(int $postId): ?SeoTagData;
}

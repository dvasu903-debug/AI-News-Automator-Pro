<?php
/**
 * The default (and, for now, only) SeoProviderInterface implementation.
 * Thin delegation to MetaTagBuilder — kept separate from the interface
 * itself so a future second provider (Google Discover, News SEO,
 * WooCommerce SEO, ...) can implement SeoProviderInterface directly
 * without depending on this class.
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Services;

use AINewsAutomator\Seo\Contracts\SeoProviderInterface;
use AINewsAutomator\Seo\DTO\SeoTagData;

final class DefaultSeoProvider implements SeoProviderInterface
{
    public function __construct(private readonly MetaTagBuilder $builder)
    {
    }

    public function provide(int $postId): ?SeoTagData
    {
        return $this->builder->build($postId);
    }
}

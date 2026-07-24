<?php
/**
 * SEO module's service provider. Depends on Publishing's frozen
 * DraftSeoRepositoryInterface (read-only) and Research's frozen
 * SessionRepositoryInterface — no new table, no changes to any frozen
 * Module 1-8 file. See planning/MODULE_9_SEO_ENGINE_DESIGN.md.
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Seo\Contracts\InternalLinkSuggesterInterface;
use AINewsAutomator\Seo\Contracts\SeoProviderInterface;
use AINewsAutomator\Seo\Frontend\SeoHeadRenderer;
use AINewsAutomator\Seo\Health\SeoHealthCheck;
use AINewsAutomator\Seo\Services\BreadcrumbGenerator;
use AINewsAutomator\Seo\Services\CanonicalUrlResolver;
use AINewsAutomator\Seo\Services\DefaultSeoProvider;
use AINewsAutomator\Seo\Services\InternalLinkSuggester;
use AINewsAutomator\Seo\Services\MetaTagBuilder;
use AINewsAutomator\Seo\Services\SchemaOrgGenerator;

final class SeoServiceProvider extends AbstractServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(
            SchemaOrgGenerator::class,
            static fn (): SchemaOrgGenerator => new SchemaOrgGenerator()
        );

        $container->singleton(
            CanonicalUrlResolver::class,
            static fn (): CanonicalUrlResolver => new CanonicalUrlResolver()
        );

        $container->singleton(
            MetaTagBuilder::class,
            static fn (ContainerInterface $c): MetaTagBuilder => new MetaTagBuilder(
                $c->get(DraftSeoRepositoryInterface::class),
                $c->get(SchemaOrgGenerator::class),
                $c->get(CanonicalUrlResolver::class)
            )
        );

        // The future-extensibility seam (approved alongside this
        // module's design): exactly one implementation bound today,
        // no registry/discovery machinery until a second is needed.
        $container->singleton(
            SeoProviderInterface::class,
            static fn (ContainerInterface $c): SeoProviderInterface => new DefaultSeoProvider(
                $c->get(MetaTagBuilder::class)
            )
        );

        $container->singleton(
            SeoHeadRenderer::class,
            static fn (ContainerInterface $c): SeoHeadRenderer => new SeoHeadRenderer(
                $c->get(SeoProviderInterface::class)
            )
        );

        $container->singleton(
            InternalLinkSuggesterInterface::class,
            static fn (ContainerInterface $c): InternalLinkSuggesterInterface => new InternalLinkSuggester(
                $c->get(SessionRepositoryInterface::class)
            )
        );

        $container->singleton(
            BreadcrumbGenerator::class,
            static fn (): BreadcrumbGenerator => new BreadcrumbGenerator()
        );

        $container->bind(
            SeoHealthCheck::class,
            static fn (ContainerInterface $c): SeoHealthCheck => new SeoHealthCheck(
                $c->get(SeoProviderInterface::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
        add_action('wp_head', static function () use ($container): void {
            $container->get(SeoHeadRenderer::class)->render();
        });
    }
}

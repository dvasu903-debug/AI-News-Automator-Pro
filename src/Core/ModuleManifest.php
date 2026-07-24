<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

/**
 * The Plugin Loader — decides WHICH modules are active. Distinct from
 * Plugin (the kernel), which only knows HOW to register/boot whatever
 * providers it's given. This separation means the top-level bootstrap
 * file stays a thin, declarative entry point (it just calls
 * ModuleManifest::providers() and hands the result to Plugin), and
 * enabling/disabling a module — or letting a site-specific mu-plugin
 * disable one via the filter below — never requires editing the
 * bootstrap file itself.
 */
final class ModuleManifest
{
    /**
     * Ordered list of active provider classes. Order matters: a
     * provider earlier in this list has its register() (and later,
     * its activate() if it implements ActivatableInterface) run before
     * providers later in the list. As modules with real inter-dependencies
     * are added (e.g. Queue's tables must exist before Pipeline enqueues
     * anything), this ordering is documented inline below rather than
     * left implicit.
     *
     * @return list<class-string<\AINewsAutomator\Core\Contracts\ServiceProviderInterface>>
     */
    public static function providers(): array
    {
        $providers = [
            // Core must always be first: every other module's provider
            // depends on bindings Core registers (Logger, Config, Events,
            // REST/Settings registries).
            CoreServiceProvider::class,

            // Security second: its capabilities and authorization gate must
            // exist before any later module performs an authorized action.
            \AINewsAutomator\Security\SecurityServiceProvider::class,

            // Storage third: rebinds Core's LoggerInterface and Security's
            // AuditLogRepositoryInterface/SecurityMetricsInterface to
            // table-backed implementations. Must come after both so its
            // registrations are the ones that win (see
            // StorageServiceProvider's class docblock for the mechanism).
            \AINewsAutomator\Storage\StorageServiceProvider::class,

            // AI fourth: depends on Core (config/events/logger), Security
            // (secrets vault, outbound HTTP guard, rate limiter,
            // capability gate), and Storage (AI request ledger, metrics,
            // and the reusable migration classes AI's own tables are
            // built with).
            \AINewsAutomator\AI\AIServiceProvider::class,

            // Sources fifth: depends on Core, Security (outbound HTTP
            // guard, rate limiter, secrets), and Storage (source
            // metadata, queue, metrics, and the reusable migration
            // classes for its own dedup fingerprint table).
            \AINewsAutomator\Sources\SourcesServiceProvider::class,

            // Research sixth: depends on Core, Security (authorization
            // policy extension point, rest security middleware), Storage
            // (metrics, reusable migration classes for its own tables),
            // and AI (structured-output extraction/scoring/contradiction
            // detection). Has NO dependency on Sources or on Storage's
            // ArticleRepositoryInterface — structurally enforced, see
            // ResearchServiceProvider's class docblock and the
            // Architecture Verification Report.
            \AINewsAutomator\Research\ResearchServiceProvider::class,

            // Workflow seventh: depends on Core, Security (authorization
            // policy extension point, rest security middleware), and
            // Storage (queue, job history, and the reusable migration
            // classes for its own write-once definition/run/step-result/
            // approval tables). Deliberately does NOT depend on
            // Storage\Contracts\WorkflowRepositoryInterface / ana_workflows
            // — that table remains unused by this module, reserved for
            // Module 8 — see MODULE_7_WORKFLOW_ENGINE_DESIGN.md Part 1.
            \AINewsAutomator\Workflow\WorkflowServiceProvider::class,

            // Publishing eighth: depends on Core, Storage
            // (ArticleRepositoryInterface, migration framework, event
            // dispatcher) in this milestone. Deliberately has NO
            // dependency on Storage\Contracts\WorkflowRepositoryInterface
            // / ana_workflows (superseded, see MODULE_8_PUBLISHING_ENGINE_DESIGN.md
            // §1) and no dependency on Workflow's internals beyond the
            // public ActionInterface/ActionRegistryInterface/WorkflowRunner
            // surface once the action milestone lands.
            \AINewsAutomator\Publishing\PublishingServiceProvider::class,

            // SEO ninth: depends on Publishing (DraftSeoRepositoryInterface,
            // read-only) and Research (SessionRepositoryInterface, for
            // internal-link suggestions). The first module whose output
            // renders on the public wp_head path rather than only
            // admin/REST/cron/queue — see planning/MODULE_9_SEO_ENGINE_DESIGN.md.
            \AINewsAutomator\Seo\SeoServiceProvider::class,
        ];

        /**
         * Filters the active provider manifest. Allows a site-specific
         * mu-plugin (or, in principle, a future settings toggle) to
         * disable an optional module without editing this file —
         * standard WordPress extensibility, kept even though this is
         * an "enterprise" plugin rather than a public one, because it's
         * the same mechanism a future admin-facing module toggle would
         * itself be built on.
         *
         * @param list<class-string<\AINewsAutomator\Core\Contracts\ServiceProviderInterface>> $providers
         */
        return apply_filters('ai_news_automator_active_providers', $providers);
    }
}

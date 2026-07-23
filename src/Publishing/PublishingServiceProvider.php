<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Publishing\Repositories\DraftRepository;
use AINewsAutomator\Publishing\Storage\PublishingMigrationManifest;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;
use AINewsAutomator\Storage\Migrations\MigrationRunner;

/**
 * Publishing module's service provider — Milestone 1 (storage +
 * bindings only, per MODULE_8_PUBLISHING_ENGINE_DESIGN.md's
 * incremental delivery plan). Actions, PublishingService, the REST
 * controller, and profile management are deliberately not here yet —
 * each is its own, separately tested milestone, matching how Module 7
 * was built and validated.
 *
 * Depends on Core, Security (not yet — added when the REST controller
 * milestone lands), Storage (ArticleRepositoryInterface, migration
 * framework), per the design doc's dependency graph. Has no dependency
 * on Storage\Contracts\WorkflowRepositoryInterface / ana_workflows —
 * that table remains unused, superseded by Module 7's own versioned
 * system (see MODULE_8_PUBLISHING_ENGINE_DESIGN.md §1).
 */
final class PublishingServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $container->singleton(
            DraftRepositoryInterface::class,
            static fn (ContainerInterface $c): DraftRepositoryInterface => new DraftRepository(
                $c->get(ArticleRepositoryInterface::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
        // Self-healing migration check, matching every other module's
        // established plugins_loaded pattern (Storage priority 5, AI 6,
        // Sources 7, Research/Workflow later) — Publishing runs last in
        // this phase, after every module it could conceivably depend on.
        add_action('plugins_loaded', static function () use ($container): void {
            /** @var MigrationRunner $runner */
            $runner = $container->get(MigrationRunner::class);
            $migrations = PublishingMigrationManifest::migrations();
            if ($runner->hasPending($migrations)) {
                $runner->migrate($migrations);
            }
        }, 9);
    }

    public function activate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var MigrationRunner $runner */
        $runner = $this->container->get(MigrationRunner::class);
        $runner->migrate(PublishingMigrationManifest::migrations());
    }

    public function deactivate(): void
    {
        // Nothing to pause yet — no cron/background work in this
        // milestone. Revisit once the scheduling actions land.
    }

    public function uninstall(): void
    {
        global $wpdb;

        // $table is built exclusively from this module's own fixed,
        // compile-time logical names via SchemaBuilder::tableName() —
        // identical pattern to every other module's uninstall(), and
        // the same reasoning Module 7's uninstall() documents: DROP
        // TABLE's identifier cannot be parameterized via prepare()
        // placeholders, so backtick-quoting a value from this fully-
        // trusted, zero-external-input source is correct here.
        foreach (['publishing_profiles', 'publishing_runs', 'draft_seo'] as $logicalName) {
            $table = \AINewsAutomator\Storage\Migrations\SchemaBuilder::tableName($logicalName);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see justification above; DDL identifiers cannot use prepare() placeholders.
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}

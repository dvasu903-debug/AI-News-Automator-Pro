<?php
/**
 * Publishing module's service provider.
 *
 * CHANGE (Milestone 2, this package): added singleton bindings for
 * PublishingProfileRepositoryInterface and PublishingProfileService,
 * alongside the existing Milestone 1 DraftRepositoryInterface binding.
 * Both new bindings use singleton() — see handbook §12 item 6 and the
 * Module 7 bind()/singleton() defect this rule exists to prevent.
 * PublishingProfileValidator has no state and no interface consumers
 * beyond the service, so it's also bound as a singleton for consistency
 * rather than constructed inline per resolution.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileValidatorInterface;
use AINewsAutomator\Publishing\Repositories\DraftRepository;
use AINewsAutomator\Publishing\Repositories\PublishingProfileRepository;
use AINewsAutomator\Publishing\Services\PublishingProfileService;
use AINewsAutomator\Publishing\Storage\PublishingMigrationManifest;
use AINewsAutomator\Publishing\Validation\PublishingProfileValidator;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Migrations\MigrationRunner;

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

        $container->singleton(
            PublishingProfileRepositoryInterface::class,
            static fn (ContainerInterface $c): PublishingProfileRepositoryInterface => new PublishingProfileRepository(
                $c->get(ConnectionInterface::class),
                $c->get(TransactionManagerInterface::class)
            )
        );

        $container->singleton(
            PublishingProfileValidatorInterface::class,
            static fn (): PublishingProfileValidatorInterface => new PublishingProfileValidator()
        );

        $container->singleton(
            PublishingProfileService::class,
            static fn (ContainerInterface $c): PublishingProfileService => new PublishingProfileService(
                $c->get(PublishingProfileRepositoryInterface::class),
                $c->get(PublishingProfileValidatorInterface::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
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
        // Nothing to pause yet — no cron/background work in this milestone.
    }

    public function uninstall(): void
    {
        global $wpdb;

        foreach (['publishing_profiles', 'publishing_runs', 'draft_seo'] as $logicalName) {
            $table = \AINewsAutomator\Storage\Migrations\SchemaBuilder::tableName($logicalName);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL identifiers cannot use prepare() placeholders; $table is built exclusively from this module's own fixed, compile-time logical names.
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}

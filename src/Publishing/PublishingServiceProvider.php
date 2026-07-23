<?php
/**
 * Publishing module's service provider.
 *
 * CHANGE (Milestone 3, this package): added PublisherInterface/
 * EditorialPolicyInterface bindings, the four new publish/schedule/
 * unpublish/archive Actions (registered into Workflow's
 * ActionRegistryInterface — the first real consumer of that previously-
 * unused extension point), PublishingAbilityPolicy (tagged
 * 'security.policies'), PublishingController (registered into
 * RestApiRegistry), and PublishingHealthCheck. See ADR-0018 for the
 * scope and interpretation decisions behind this milestone.
 *
 * CHANGE (Milestone 2): added singleton bindings for
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
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\RestApi\RestApiRegistry;
use AINewsAutomator\Publishing\Actions\ArchiveAction;
use AINewsAutomator\Publishing\Actions\PublishDraftAction;
use AINewsAutomator\Publishing\Actions\ScheduleDraftAction;
use AINewsAutomator\Publishing\Actions\UnpublishAction;
use AINewsAutomator\Publishing\Api\PublishingController;
use AINewsAutomator\Publishing\Authorization\PublishingAbilityPolicy;
use AINewsAutomator\Publishing\Contracts\DraftRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileValidatorInterface;
use AINewsAutomator\Publishing\Health\PublishingHealthCheck;
use AINewsAutomator\Publishing\Repositories\DraftRepository;
use AINewsAutomator\Publishing\Repositories\PublishingProfileRepository;
use AINewsAutomator\Publishing\Services\DefaultEditorialPolicy;
use AINewsAutomator\Publishing\Services\PublishingProfileService;
use AINewsAutomator\Publishing\Services\PublishingService;
use AINewsAutomator\Publishing\Storage\PublishingMigrationManifest;
use AINewsAutomator\Publishing\Validation\PublishingProfileValidator;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Migrations\MigrationRunner;
use AINewsAutomator\Workflow\Contracts\ActionRegistryInterface;

final class PublishingServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->registerRepositories($container);
        $this->registerPublishing($container);
        $this->registerActions($container);
        $this->registerAuthorization($container);
        $this->registerHealth($container);
        $this->registerRestApi($container);
    }

    private function registerRepositories(ContainerInterface $container): void
    {
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

    private function registerPublishing(ContainerInterface $container): void
    {
        $container->singleton(
            EditorialPolicyInterface::class,
            static fn (ContainerInterface $c): EditorialPolicyInterface => new DefaultEditorialPolicy(
                $c->get(DraftRepositoryInterface::class)
            )
        );

        $container->singleton(
            PublisherInterface::class,
            static fn (ContainerInterface $c): PublisherInterface => new PublishingService(
                $c->get(ArticleRepositoryInterface::class),
                $c->get(DraftRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class)
            )
        );
    }

    private function registerActions(ContainerInterface $container): void
    {
        $container->bind(
            PublishDraftAction::class,
            static fn (ContainerInterface $c): PublishDraftAction => new PublishDraftAction(
                $c->get(PublisherInterface::class),
                $c->get(EditorialPolicyInterface::class),
                $c->get(PublishingProfileRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class)
            )
        );

        $container->bind(
            ScheduleDraftAction::class,
            static fn (ContainerInterface $c): ScheduleDraftAction => new ScheduleDraftAction(
                $c->get(PublisherInterface::class),
                $c->get(EditorialPolicyInterface::class),
                $c->get(PublishingProfileRepositoryInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class)
            )
        );

        $container->bind(
            UnpublishAction::class,
            static fn (ContainerInterface $c): UnpublishAction => new UnpublishAction($c->get(PublisherInterface::class))
        );

        $container->bind(
            ArchiveAction::class,
            static fn (ContainerInterface $c): ArchiveAction => new ArchiveAction($c->get(PublisherInterface::class))
        );
    }

    private function registerAuthorization(ContainerInterface $container): void
    {
        $container->bind(
            PublishingAbilityPolicy::class,
            static fn (): PublishingAbilityPolicy => new PublishingAbilityPolicy()
        );
        $container->tag(PublishingAbilityPolicy::class, 'security.policies');
    }

    private function registerHealth(ContainerInterface $container): void
    {
        $container->bind(
            PublishingHealthCheck::class,
            static fn (ContainerInterface $c): PublishingHealthCheck => new PublishingHealthCheck(
                $c->get(PublishingProfileRepositoryInterface::class)
            )
        );
    }

    private function registerRestApi(ContainerInterface $container): void
    {
        $container->bind(
            PublishingController::class,
            static fn (ContainerInterface $c): PublishingController => new PublishingController(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(RestSecurityMiddleware::class),
                $c->get(PublishingProfileService::class),
                $c->get(PublisherInterface::class)
            )
        );

        /** @var RestApiRegistry $rest */
        $rest = $container->get(RestApiRegistry::class);
        $rest->register(PublishingController::class);
    }

    public function boot(ContainerInterface $container): void
    {
        // Register this module's actions into Workflow's own registry —
        // the first real consumer of the documented-but-previously-unused
        // extension point (see ActionRegistryInterface's docblock).
        // Singleton (registered during Workflow's register() phase, see
        // WorkflowServiceProvider::registerRunner()), so it doesn't
        // matter whether Workflow's or Publishing's boot() runs first —
        // both populate the one shared instance before any workflow run
        // actually looks an action up by type().
        /** @var ActionRegistryInterface $registry */
        $registry = $container->get(ActionRegistryInterface::class);
        $registry->register($container->get(PublishDraftAction::class));
        $registry->register($container->get(ScheduleDraftAction::class));
        $registry->register($container->get(UnpublishAction::class));
        $registry->register($container->get(ArchiveAction::class));

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

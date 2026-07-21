<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Settings\SettingsRegistry;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Sources\Admin\SourcesSettingsPage;
use AINewsAutomator\Sources\Connectors\JsonFeedConnector;
use AINewsAutomator\Sources\Connectors\RssConnector;
use AINewsAutomator\Sources\Connectors\SitemapConnector;
use AINewsAutomator\Sources\Connectors\WebCrawlerConnector;
use AINewsAutomator\Sources\Contracts\DeduplicationInterface;
use AINewsAutomator\Sources\Contracts\RobotsTxtCheckerInterface;
use AINewsAutomator\Sources\Contracts\SourceConnectorRegistryInterface;
use AINewsAutomator\Sources\Contracts\SourceItemRepositoryInterface;
use AINewsAutomator\Sources\Contracts\SourceReputationInterface;
use AINewsAutomator\Sources\Contracts\SourceValidatorInterface;
use AINewsAutomator\Sources\Dedup\FingerprintDeduplicator;
use AINewsAutomator\Sources\Dedup\SourceItemRepository;
use AINewsAutomator\Sources\Health\SourceHealthCheck;
use AINewsAutomator\Sources\Jobs\CrawlUrlJobHandler;
use AINewsAutomator\Sources\Jobs\FetchSourceJobHandler;
use AINewsAutomator\Sources\Registry\SourceConnectorRegistry;
use AINewsAutomator\Sources\Reputation\MetricsBackedReputationScorer;
use AINewsAutomator\Sources\Retry\SourceRetryExecutor;
use AINewsAutomator\Sources\Robots\RobotsTxtChecker;
use AINewsAutomator\Sources\Scheduling\SourceSyncScheduler;
use AINewsAutomator\Sources\Storage\SourcesMigrationManifest;
use AINewsAutomator\Sources\Validation\SourceValidator;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;
use AINewsAutomator\Storage\Migrations\MigrationRunner;

/**
 * The Sources module's single service provider. Registers connectors
 * (never instantiated outside this file — resolved everywhere else
 * through SourceConnectorRegistryInterface), the dedup fingerprint
 * repository (Sources-owned table, reusing Storage's migration classes
 * per ADR-0006), the narrow retry executor and scheduler (ADR-0016), and
 * the settings/health/admin surface.
 */
final class SourcesServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->registerDedup($container);
        $this->registerRobotsAndConnectors($container);
        $this->registerValidationAndReputation($container);
        $this->registerRetryAndJobs($container);
        $this->registerScheduler($container);
        $this->registerHealthAndAdmin($container);
    }

    private function registerDedup(ContainerInterface $container): void
    {
        $container->singleton(
            SourceItemRepositoryInterface::class,
            static fn (ContainerInterface $c): SourceItemRepositoryInterface => new SourceItemRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            DeduplicationInterface::class,
            static fn (ContainerInterface $c): DeduplicationInterface => new FingerprintDeduplicator($c->get(SourceItemRepositoryInterface::class))
        );
    }

    private function registerRobotsAndConnectors(ContainerInterface $container): void
    {
        $container->singleton(
            RobotsTxtCheckerInterface::class,
            static fn (ContainerInterface $c): RobotsTxtCheckerInterface => new RobotsTxtChecker(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class)
            )
        );

        $container->singleton(
            RssConnector::class,
            static fn (ContainerInterface $c): RssConnector => new RssConnector(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class),
                $c->get(RateLimiterInterface::class)
            )
        );

        $container->singleton(
            JsonFeedConnector::class,
            static fn (ContainerInterface $c): JsonFeedConnector => new JsonFeedConnector(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class),
                $c->get(RateLimiterInterface::class),
                $c->get(SecretsProviderInterface::class)
            )
        );

        $container->singleton(
            WebCrawlerConnector::class,
            static fn (ContainerInterface $c): WebCrawlerConnector => new WebCrawlerConnector(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class),
                $c->get(RateLimiterInterface::class),
                $c->get(RobotsTxtCheckerInterface::class)
            )
        );

        $container->singleton(
            SitemapConnector::class,
            static fn (ContainerInterface $c): SitemapConnector => new SitemapConnector(
                $c->get(OutboundHttpValidator::class),
                $c->get(LoggerInterface::class),
                $c->get(RateLimiterInterface::class),
                $c->get(RobotsTxtCheckerInterface::class)
            )
        );

        $container->singleton(
            SourceConnectorRegistryInterface::class,
            static fn (): SourceConnectorRegistryInterface => new SourceConnectorRegistry()
        );
    }

    private function registerValidationAndReputation(ContainerInterface $container): void
    {
        $container->singleton(
            SourceValidatorInterface::class,
            static fn (ContainerInterface $c): SourceValidatorInterface => new SourceValidator($c->get(SourceConnectorRegistryInterface::class))
        );

        $container->singleton(
            SourceReputationInterface::class,
            static fn (ContainerInterface $c): SourceReputationInterface => new MetricsBackedReputationScorer($c->get(MetricsRepositoryInterface::class))
        );
    }

    private function registerRetryAndJobs(ContainerInterface $container): void
    {
        $container->singleton(
            SourceRetryExecutor::class,
            static fn (ContainerInterface $c): SourceRetryExecutor => new SourceRetryExecutor($c->get(LoggerInterface::class))
        );

        $container->singleton(
            FetchSourceJobHandler::class,
            static fn (ContainerInterface $c): FetchSourceJobHandler => new FetchSourceJobHandler(
                $c->get(SourceValidatorInterface::class),
                $c->get(DeduplicationInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class),
                $c->get(LoggerInterface::class),
                $c->get(SourceConnectorRegistryInterface::class),
                $c->get(SourceRepositoryInterface::class),
                $c->get(SourceRetryExecutor::class),
                $c->get(MetricsRepositoryInterface::class)
            )
        );

        $container->singleton(
            CrawlUrlJobHandler::class,
            static fn (ContainerInterface $c): CrawlUrlJobHandler => new CrawlUrlJobHandler(
                $c->get(SourceValidatorInterface::class),
                $c->get(DeduplicationInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class),
                $c->get(LoggerInterface::class),
                $c->get(SourceConnectorRegistryInterface::class),
                $c->get(SourceRepositoryInterface::class),
                $c->get(SourceRetryExecutor::class),
                $c->get(MetricsRepositoryInterface::class)
            )
        );
    }

    private function registerScheduler(ContainerInterface $container): void
    {
        $container->singleton(
            SourceSyncScheduler::class,
            static fn (ContainerInterface $c): SourceSyncScheduler => new SourceSyncScheduler(
                $c->get(SourceRepositoryInterface::class),
                $c->get(QueueRepositoryInterface::class),
                $c->get(FetchSourceJobHandler::class),
                $c->get(CrawlUrlJobHandler::class),
                $c->get(LoggerInterface::class)
            )
        );
    }

    private function registerHealthAndAdmin(ContainerInterface $container): void
    {
        $container->singleton(
            SourceHealthCheck::class,
            static fn (ContainerInterface $c): SourceHealthCheck => new SourceHealthCheck(
                $c->get(SourceRepositoryInterface::class),
                $c->get(SourceConnectorRegistryInterface::class),
                $c->get(SourceReputationInterface::class)
            )
        );

        $container->singleton(
            SourcesSettingsPage::class,
            static fn (ContainerInterface $c): SourcesSettingsPage => new SourcesSettingsPage(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(SourceHealthCheck::class),
                $c->get(SourceRepositoryInterface::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
        $this->populateConnectorRegistry($container);

        /** @var SettingsRegistry $settings */
        $settings = $container->get(SettingsRegistry::class);
        $settings->register(SourcesSettingsPage::class);

        $scheduler = $container->get(SourceSyncScheduler::class);

        add_filter('cron_schedules', [$scheduler, 'registerCronInterval']);
        add_action(SourceSyncScheduler::hookName(), [$scheduler, 'tick']);

        // Automatic upgrade detection for the Sources module's own table —
        // same pattern as Storage's and AI's own boot(), reusing Storage's
        // MigrationRunner singleton with the Sources module's own manifest.
        add_action('plugins_loaded', static function () use ($container): void {
            /** @var MigrationRunner $runner */
            $runner = $container->get(MigrationRunner::class);
            $migrations = SourcesMigrationManifest::migrations();

            if ($runner->hasPending($migrations)) {
                $runner->migrate($migrations);
            }
        }, 7); // After Storage's (5) and AI's (6) own checks.
    }

    private function populateConnectorRegistry(ContainerInterface $container): void
    {
        /** @var SourceConnectorRegistryInterface $registry */
        $registry = $container->get(SourceConnectorRegistryInterface::class);

        $registry->register($container->get(RssConnector::class));
        $registry->register($container->get(JsonFeedConnector::class));
        $registry->register($container->get(WebCrawlerConnector::class));
        $registry->register($container->get(SitemapConnector::class));
    }

    public function activate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var MigrationRunner $runner */
        $runner = $this->container->get(MigrationRunner::class);
        $runner->migrate(SourcesMigrationManifest::migrations());

        /** @var SourceSyncScheduler $scheduler */
        $scheduler = $this->container->get(SourceSyncScheduler::class);
        $scheduler->schedule();
    }

    public function deactivate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var SourceSyncScheduler $scheduler */
        $scheduler = $this->container->get(SourceSyncScheduler::class);
        $scheduler->unschedule();
    }

    public function uninstall(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ana_source_items';
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
    }
}

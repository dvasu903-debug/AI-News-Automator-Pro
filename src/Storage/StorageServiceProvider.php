<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface as CoreLoggerInterface;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Security\Contracts\AuditLogRepositoryInterface;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Storage\Contracts\AiRequestRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ArticleRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\ExporterInterface;
use AINewsAutomator\Storage\Contracts\ImageRepositoryInterface;
use AINewsAutomator\Storage\Contracts\ImporterInterface;
use AINewsAutomator\Storage\Contracts\JobHistoryRepositoryInterface;
use AINewsAutomator\Storage\Contracts\LogRepositoryInterface;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Contracts\QueryBuilderInterface;
use AINewsAutomator\Storage\Contracts\QueryProfilerInterface;
use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Storage\Contracts\RetentionPolicyInterface;
use AINewsAutomator\Storage\Contracts\SettingsRepositoryInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Contracts\WorkflowRepositoryInterface;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Database\SchemaInspector;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Database\TransactionManager;
use AINewsAutomator\Storage\ExportImport\BackupManager;
use AINewsAutomator\Storage\ExportImport\SourcesExporter;
use AINewsAutomator\Storage\ExportImport\SourcesImporter;
use AINewsAutomator\Storage\ExportImport\WorkflowsExporter;
use AINewsAutomator\Storage\ExportImport\WorkflowsImporter;
use AINewsAutomator\Storage\Health\StorageHealthCheck;
use AINewsAutomator\Storage\Migrations\MigrationManifest;
use AINewsAutomator\Storage\Migrations\MigrationRecorder;
use AINewsAutomator\Storage\Migrations\MigrationRunner;
use AINewsAutomator\Storage\Profiling\NullQueryProfiler;
use AINewsAutomator\Storage\Repositories\AiRequestRepository;
use AINewsAutomator\Storage\Repositories\ArticleRepository;
use AINewsAutomator\Storage\Repositories\AuditRepository;
use AINewsAutomator\Storage\Repositories\ImageRepository;
use AINewsAutomator\Storage\Repositories\JobHistoryRepository;
use AINewsAutomator\Storage\Repositories\LogRepository;
use AINewsAutomator\Storage\Repositories\MetricsRepository;
use AINewsAutomator\Storage\Repositories\QueueRepository;
use AINewsAutomator\Storage\Repositories\SettingsRepository;
use AINewsAutomator\Storage\Repositories\SourceRepository;
use AINewsAutomator\Storage\Repositories\TableBackedLogger;
use AINewsAutomator\Storage\Repositories\TableBackedSecurityMetrics;
use AINewsAutomator\Storage\Repositories\WorkflowRepository;
use AINewsAutomator\Storage\Retention\RetentionCleanupJob;
use AINewsAutomator\Storage\Retention\RetentionPolicy;

/**
 * The Storage module's single service provider. Registers the database
 * layer, all ten domain repositories, and — per the approved
 * implementation requirements — REBINDS three interfaces that Core and
 * Security previously bound to option-backed defaults:
 *
 *   - Core\Contracts\LoggerInterface            -> TableBackedLogger
 *   - Security\Contracts\AuditLogRepositoryInterface -> AuditRepository
 *   - Security\Contracts\SecurityMetricsInterface    -> TableBackedSecurityMetrics
 *
 * This works purely through container registration order (see
 * ARCHITECTURE_PLAN.md §2.9 / Module 2 README): StorageServiceProvider is
 * positioned after Core and Security in ModuleManifest, so its
 * singleton() calls for these interfaces simply overwrite the earlier
 * bindings during the register phase, before anything has been resolved.
 * Neither Core's nor Security's files are touched.
 */
final class StorageServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->registerDatabaseLayer($container);
        $this->registerMigrations($container);
        $this->registerRepositories($container);
        $this->registerRebindings($container);
        $this->registerRetention($container);
        $this->registerExportImport($container);
        $this->registerHealth($container);
    }

    public function boot(ContainerInterface $container): void
    {
        // Automatic upgrade detection: on every normal request, a cheap
        // check for pending migrations (the recorder's table-existence +
        // one SELECT) catches "site files were upgraded without
        // reactivating the plugin" — not just fresh activation.
        add_action('plugins_loaded', static function () use ($container): void {
            /** @var MigrationRunner $runner */
            $runner = $container->get(MigrationRunner::class);
            $migrations = MigrationManifest::migrations();

            if ($runner->hasPending($migrations)) {
                $runner->migrate($migrations);
            }
        }, 5); // Priority 5: before Core's own plugins_loaded work (default 10).
    }

    private function registerDatabaseLayer(ContainerInterface $container): void
    {
        $container->singleton(QueryProfilerInterface::class, static fn (): QueryProfilerInterface => new NullQueryProfiler());

        $container->singleton(
            ConnectionInterface::class,
            static fn (ContainerInterface $c): ConnectionInterface => new Connection($c->get(QueryProfilerInterface::class))
        );

        $container->singleton(Connection::class, static fn (ContainerInterface $c): Connection => $c->get(ConnectionInterface::class));

        $container->singleton(TransactionManagerInterface::class, static fn (): TransactionManagerInterface => new TransactionManager());

        $container->singleton(
            SchemaInspector::class,
            static fn (ContainerInterface $c): SchemaInspector => new SchemaInspector($c->get(Connection::class))
        );
    }

    private function registerMigrations(ContainerInterface $container): void
    {
        $container->singleton(
            MigrationRecorder::class,
            static fn (ContainerInterface $c): MigrationRecorder => new MigrationRecorder(
                $c->get(ConnectionInterface::class),
                $c->get(SchemaInspector::class)
            )
        );

        $container->singleton(
            MigrationRunner::class,
            static fn (ContainerInterface $c): MigrationRunner => new MigrationRunner(
                $c->get(ConnectionInterface::class),
                $c->get(TransactionManagerInterface::class),
                $c->get(MigrationRecorder::class),
                // Deliberately NOT $c->get(CoreLoggerInterface::class): by the
                // time MigrationRunner is first resolved (Activator ->
                // activate()), this provider's own registerDatabaseLayer()
                // has already rebound CoreLoggerInterface to TableBackedLogger,
                // which persists to ana_logs — the very table a from-scratch
                // migration run exists to create. MigrationRunner's own
                // progress logging must never depend on a table migrations
                // themselves are responsible for creating, so it gets its own
                // fixed, table-independent OptionBackedLogger instance (writes
                // to wp_options — always available) instead of the shared,
                // rebindable LoggerInterface every other class uses. This is
                // scoped to MigrationRunner's internal logging only; every
                // other consumer of LoggerInterface still resolves
                // TableBackedLogger exactly as before.
                new OptionBackedLogger($c->get(CorrelationContext::class), $c->get(Environment::class))
            )
        );
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->singleton(SettingsRepositoryInterface::class, static fn (): SettingsRepositoryInterface => new SettingsRepository());

        $container->singleton(
            JobHistoryRepositoryInterface::class,
            static fn (ContainerInterface $c): JobHistoryRepositoryInterface => new JobHistoryRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            QueueRepositoryInterface::class,
            static fn (ContainerInterface $c): QueueRepositoryInterface => new QueueRepository(
                $c->get(ConnectionInterface::class),
                $c->get(TransactionManagerInterface::class),
                $c->get(JobHistoryRepositoryInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class)
            )
        );

        $container->singleton(
            LogRepositoryInterface::class,
            static fn (ContainerInterface $c): LogRepositoryInterface => new LogRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            AuditRepository::class,
            static fn (ContainerInterface $c): AuditRepository => new AuditRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            MetricsRepositoryInterface::class,
            static fn (ContainerInterface $c): MetricsRepositoryInterface => new MetricsRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            SourceRepositoryInterface::class,
            static fn (ContainerInterface $c): SourceRepositoryInterface => new SourceRepository(
                $c->get(ConnectionInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class)
            )
        );

        $container->singleton(
            WorkflowRepositoryInterface::class,
            static fn (ContainerInterface $c): WorkflowRepositoryInterface => new WorkflowRepository(
                $c->get(ConnectionInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class)
            )
        );

        $container->singleton(
            AiRequestRepositoryInterface::class,
            static fn (ContainerInterface $c): AiRequestRepositoryInterface => new AiRequestRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            ImageRepositoryInterface::class,
            static fn (ContainerInterface $c): ImageRepositoryInterface => new ImageRepository(
                $c->get(ConnectionInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class)
            )
        );

        $container->singleton(
            ArticleRepositoryInterface::class,
            static fn (ContainerInterface $c): ArticleRepositoryInterface => new ArticleRepository(
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class)
            )
        );
    }

    /**
     * The three explicit rebindings. Positioned last among the register()
     * sub-steps within this provider for readability only — actual
     * override behavior depends on provider (not method) ordering, since
     * every provider's register() runs before any boot().
     */
    private function registerRebindings(ContainerInterface $container): void
    {
        // 1. Audit — explicit requirement, and the seam Security's own
        // Module 2 documentation anticipated.
        $container->singleton(
            AuditLogRepositoryInterface::class,
            static fn (ContainerInterface $c): AuditLogRepositoryInterface => $c->get(AuditRepository::class)
        );

        // 2. Logger — supersedes Core's OptionBackedLogger.
        $container->singleton(
            CoreLoggerInterface::class,
            static fn (ContainerInterface $c): CoreLoggerInterface => new TableBackedLogger(
                $c->get(LogRepositoryInterface::class),
                $c->get(CorrelationContext::class),
                $c->get(Environment::class)
            )
        );

        // 3. Security metrics — supersedes Security's option-backed
        // SecurityMetrics, fixing the read-modify-write race (audit item W2).
        $container->singleton(
            SecurityMetricsInterface::class,
            static fn (ContainerInterface $c): SecurityMetricsInterface => new TableBackedSecurityMetrics(
                $c->get(MetricsRepositoryInterface::class)
            )
        );
    }

    private function registerRetention(ContainerInterface $container): void
    {
        $container->singleton(
            RetentionCleanupJob::class,
            static function (ContainerInterface $c): RetentionCleanupJob {
                $config = $c->get(ConfigRepositoryInterface::class);

                $policies = [
                    new RetentionPolicy(
                        Tables::LOGS,
                        $c->get(LogRepositoryInterface::class),
                        (int) $config->get('storage.retention.logs_days', 30)
                    ),
                    new RetentionPolicy(
                        Tables::AUDIT,
                        $c->get(AuditRepository::class),
                        (int) $config->get('storage.retention.audit_days', 180)
                    ),
                    new RetentionPolicy(
                        Tables::JOBS,
                        $c->get(JobHistoryRepositoryInterface::class),
                        (int) $config->get('storage.retention.job_history_days', 60)
                    ),
                    new RetentionPolicy(
                        Tables::METRICS,
                        $c->get(MetricsRepositoryInterface::class),
                        (int) $config->get('storage.retention.metrics_days', 90)
                    ),
                ];

                return new RetentionCleanupJob($policies, $c->get(ConnectionInterface::class), $c->get(CoreLoggerInterface::class));
            }
        );
    }

    private function registerExportImport(ContainerInterface $container): void
    {
        $container->singleton(
            BackupManager::class,
            static function (ContainerInterface $c): BackupManager {
                /** @var list<ExporterInterface> $exporters */
                $exporters = [
                    new SourcesExporter($c->get(SourceRepositoryInterface::class)),
                    new WorkflowsExporter($c->get(WorkflowRepositoryInterface::class)),
                ];

                /** @var list<ImporterInterface> $importers */
                $importers = [
                    new SourcesImporter($c->get(SourceRepositoryInterface::class)),
                    new WorkflowsImporter($c->get(WorkflowRepositoryInterface::class)),
                ];

                return new BackupManager($exporters, $importers);
            }
        );
    }

    private function registerHealth(ContainerInterface $container): void
    {
        $container->singleton(
            StorageHealthCheck::class,
            static fn (ContainerInterface $c): StorageHealthCheck => new StorageHealthCheck(
                $c->get(ConnectionInterface::class),
                $c->get(SchemaInspector::class),
                $c->get(MigrationRecorder::class)
            )
        );
    }

    public function activate(): void
    {
        // ActivatableInterface::activate() receives no container parameter,
        // so the reference captured in register() (by which point every
        // binding above already exists) is what lets this method actually
        // resolve and run the migration runner — this is what creates
        // every table on first install. (Missing this call was caught and
        // fixed during implementation review: an earlier draft of this
        // method was an empty stub with only a descriptive comment.)
        if ($this->container === null) {
            return;
        }

        /** @var MigrationRunner $runner */
        $runner = $this->container->get(MigrationRunner::class);
        $runner->migrate(MigrationManifest::migrations());
    }

    public function deactivate(): void
    {
        // Reversible — no data destroyed on plain deactivation.
    }

    public function uninstall(): void
    {
        // Only reached on full plugin deletion via wp-admin. Drops every
        // Storage-owned table and cleans up the legacy option keys this
        // module supersedes (ai_news_automator_log, _audit_log,
        // _security_metrics) — the CredentialVault's option
        // (ai_news_automator_secrets) is Security's own and is left to
        // Security's uninstall(), unchanged, per the freeze.
        global $wpdb;

        foreach (array_reverse(Tables::all()) as $logical) {
            $table = $wpdb->prefix . 'ana_' . $logical;
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option('ai_news_automator_log');
        delete_option('ai_news_automator_audit_log');
        delete_option('ai_news_automator_security_metrics');
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\RestApi\RestApiRegistry;
use AINewsAutomator\Core\Settings\SettingsRegistry;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\JobHistoryRepositoryInterface;
use AINewsAutomator\Storage\Contracts\QueueRepositoryInterface;
use AINewsAutomator\Storage\Events\JobCompletedEvent;
use AINewsAutomator\Storage\Events\JobFailedEvent;
use AINewsAutomator\Storage\Migrations\MigrationRunner;
use AINewsAutomator\Workflow\Actions\ApprovalGateAction;
use AINewsAutomator\Workflow\Actions\BranchAction;
use AINewsAutomator\Workflow\Actions\NotificationAction;
use AINewsAutomator\Workflow\Actions\QueueJobAction;
use AINewsAutomator\Workflow\Actions\WaitAction;
use AINewsAutomator\Workflow\Admin\WorkflowSettingsPage;
use AINewsAutomator\Workflow\Api\WorkflowController;
use AINewsAutomator\Workflow\Authorization\WorkflowAbilityPolicy;
use AINewsAutomator\Workflow\Contracts\ActionRegistryInterface;
use AINewsAutomator\Workflow\Contracts\ApprovalRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\ConditionEvaluatorInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowDefinitionRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowRunRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowStepResultRepositoryInterface;
use AINewsAutomator\Workflow\Health\WorkflowHealthCheck;
use AINewsAutomator\Workflow\Registry\ActionRegistry;
use AINewsAutomator\Workflow\Repositories\ApprovalRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowDefinitionRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowRunRepository;
use AINewsAutomator\Workflow\Repositories\WorkflowStepResultRepository;
use AINewsAutomator\Workflow\Retry\WorkflowStepRetryExecutor;
use AINewsAutomator\Workflow\Runner\ConditionEvaluator;
use AINewsAutomator\Workflow\Runner\QueueCompletionListener;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;
use AINewsAutomator\Workflow\Scheduling\WorkflowScheduler;
use AINewsAutomator\Storage\Migrations\SchemaBuilder;
use AINewsAutomator\Workflow\Storage\WorkflowMigrationManifest;

/**
 * The Workflow module's single service provider. Registers the
 * write-once definition repository (Option A — never uses
 * Storage\Contracts\WorkflowRepositoryInterface / ana_workflows, see
 * Part 1), the generic actions every workflow can use out of the box,
 * the narrow retry executor and scheduler (ADR-0016/ADR-0017), the
 * queue-completion bridge for deferred steps (Decision 3), and the
 * settings/health/REST/authorization surface — the same shape every
 * prior module's service provider follows.
 */
final class WorkflowServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->registerRepositories($container);
        $this->registerRunner($container);
        $this->registerActions($container);
        $this->registerRetryAndScheduler($container);
        $this->registerAuthorization($container);
        $this->registerHealthAndAdmin($container);
        $this->registerRestApi($container);
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->bind(
            WorkflowDefinitionRepositoryInterface::class,
            static fn (ContainerInterface $c): WorkflowDefinitionRepositoryInterface => new WorkflowDefinitionRepository($c->get(ConnectionInterface::class))
        );

        $container->bind(
            WorkflowRunRepositoryInterface::class,
            static fn (ContainerInterface $c): WorkflowRunRepositoryInterface => new WorkflowRunRepository($c->get(ConnectionInterface::class))
        );

        $container->bind(
            WorkflowStepResultRepositoryInterface::class,
            static fn (ContainerInterface $c): WorkflowStepResultRepositoryInterface => new WorkflowStepResultRepository($c->get(ConnectionInterface::class))
        );

        $container->bind(
            ApprovalRepositoryInterface::class,
            static fn (ContainerInterface $c): ApprovalRepositoryInterface => new ApprovalRepository($c->get(ConnectionInterface::class))
        );
    }

    private function registerRunner(ContainerInterface $container): void
    {
        // Singleton, not bind(): populateActionRegistry() (called once,
        // during boot()) registers every action into ONE instance of this
        // registry. WorkflowRunner later autowires ActionRegistryInterface
        // as a constructor dependency — with bind() (a fresh instance per
        // resolution), it would receive a completely different, empty
        // registry, and every action type would appear unregistered at
        // runtime despite registration having "succeeded" at boot. Unit
        // tests never caught this: they construct WorkflowRunner directly
        // with a manually-built registry, bypassing the container's
        // binding behavior entirely — this was only ever reachable through
        // real, full-container runtime execution.
        $container->singleton(
            ActionRegistryInterface::class,
            static fn (): ActionRegistryInterface => new ActionRegistry()
        );

        $container->bind(
            ConditionEvaluatorInterface::class,
            static fn (): ConditionEvaluatorInterface => new ConditionEvaluator()
        );

        $container->bind(
            WorkflowRunner::class,
            static fn (ContainerInterface $c): WorkflowRunner => new WorkflowRunner(
                $c->get(WorkflowDefinitionRepositoryInterface::class),
                $c->get(WorkflowRunRepositoryInterface::class),
                $c->get(WorkflowStepResultRepositoryInterface::class),
                $c->get(ApprovalRepositoryInterface::class),
                $c->get(ActionRegistryInterface::class),
                $c->get(ConditionEvaluatorInterface::class),
                $c->get(WorkflowStepRetryExecutor::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class),
                $c->get(CorrelationContext::class),
                $c->get(LoggerInterface::class)
            )
        );

        $container->bind(
            QueueCompletionListener::class,
            static fn (ContainerInterface $c): QueueCompletionListener => new QueueCompletionListener(
                $c->get(WorkflowRunner::class),
                $c->get(JobHistoryRepositoryInterface::class),
                $c->get(LoggerInterface::class)
            )
        );
    }

    private function registerActions(ContainerInterface $container): void
    {
        $container->bind(WaitAction::class, static fn (): WaitAction => new WaitAction());
        $container->bind(BranchAction::class, static fn (): BranchAction => new BranchAction());
        $container->bind(ApprovalGateAction::class, static fn (): ApprovalGateAction => new ApprovalGateAction());

        $container->bind(
            NotificationAction::class,
            static fn (ContainerInterface $c): NotificationAction => new NotificationAction($c->get(LoggerInterface::class))
        );

        $container->bind(
            QueueJobAction::class,
            static fn (ContainerInterface $c): QueueJobAction => new QueueJobAction($c->get(QueueRepositoryInterface::class))
        );
    }

    private function registerRetryAndScheduler(ContainerInterface $container): void
    {
        $container->bind(
            WorkflowStepRetryExecutor::class,
            static fn (ContainerInterface $c): WorkflowStepRetryExecutor => new WorkflowStepRetryExecutor($c->get(LoggerInterface::class))
        );

        $container->bind(
            WorkflowScheduler::class,
            static fn (ContainerInterface $c): WorkflowScheduler => new WorkflowScheduler(
                $c->get(WorkflowDefinitionRepositoryInterface::class),
                $c->get(QueueRepositoryInterface::class),
                $c->get(WorkflowRunner::class),
                $c->get(LoggerInterface::class)
            )
        );
    }

    private function registerAuthorization(ContainerInterface $container): void
    {
        $container->bind(WorkflowAbilityPolicy::class, static fn (): WorkflowAbilityPolicy => new WorkflowAbilityPolicy());
        $container->tag(WorkflowAbilityPolicy::class, 'security.policies');
    }

    private function registerHealthAndAdmin(ContainerInterface $container): void
    {
        $container->bind(
            WorkflowHealthCheck::class,
            static fn (ContainerInterface $c): WorkflowHealthCheck => new WorkflowHealthCheck($c->get(WorkflowRunRepositoryInterface::class))
        );

        $container->bind(
            WorkflowSettingsPage::class,
            static fn (ContainerInterface $c): WorkflowSettingsPage => new WorkflowSettingsPage(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(WorkflowHealthCheck::class)
            )
        );
    }

    private function registerRestApi(ContainerInterface $container): void
    {
        $container->bind(
            WorkflowController::class,
            static fn (ContainerInterface $c): WorkflowController => new WorkflowController(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(RestSecurityMiddleware::class),
                $c->get(WorkflowDefinitionRepositoryInterface::class),
                $c->get(WorkflowRunRepositoryInterface::class),
                $c->get(WorkflowStepResultRepositoryInterface::class),
                $c->get(WorkflowRunner::class)
            )
        );

        /** @var RestApiRegistry $rest */
        $rest = $container->get(RestApiRegistry::class);
        $rest->register(WorkflowController::class);
    }

    public function boot(ContainerInterface $container): void
    {
        $this->populateActionRegistry($container);

        /** @var SettingsRegistry $settings */
        $settings = $container->get(SettingsRegistry::class);
        $settings->register(WorkflowSettingsPage::class);

        $scheduler = $container->get(WorkflowScheduler::class);

        add_filter('cron_schedules', [$scheduler, 'registerCronInterval']);
        add_action(WorkflowScheduler::hookName(), [$scheduler, 'tick']);

        // Bridges Storage's existing queue-completion events to
        // WorkflowRunner::resumeFromQueueJob() — the "existing
        // job-completion mechanism" reused per Decision 3.
        /** @var EventDispatcherInterface $events */
        $events = $container->get(EventDispatcherInterface::class);
        $listener = $container->get(QueueCompletionListener::class);

        $events->addListener(JobCompletedEvent::class, [$listener, 'onJobCompleted']);
        $events->addListener(JobFailedEvent::class, [$listener, 'onJobFailed']);

        // Automatic upgrade detection for Workflow's own tables — same
        // pattern as every prior module's boot(), reusing Storage's
        // MigrationRunner singleton with Workflow's own manifest.
        add_action('plugins_loaded', static function () use ($container): void {
            /** @var MigrationRunner $runner */
            $runner = $container->get(MigrationRunner::class);
            $migrations = WorkflowMigrationManifest::migrations();

            if ($runner->hasPending($migrations)) {
                $runner->migrate($migrations);
            }
        }, 8); // After Storage's (5), AI's (6), Sources' (7) own checks.
    }

    private function populateActionRegistry(ContainerInterface $container): void
    {
        /** @var ActionRegistryInterface $registry */
        $registry = $container->get(ActionRegistryInterface::class);

        // Workflow's own generic actions only — every other module
        // registers its own action(s) from its own service provider's
        // boot(), exactly like Sources' connectors / AI's providers.
        $registry->register($container->get(WaitAction::class));
        $registry->register($container->get(BranchAction::class));
        $registry->register($container->get(ApprovalGateAction::class));
        $registry->register($container->get(NotificationAction::class));
        $registry->register($container->get(QueueJobAction::class));
    }

    public function activate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var MigrationRunner $runner */
        $runner = $this->container->get(MigrationRunner::class);
        $runner->migrate(WorkflowMigrationManifest::migrations());

        /** @var WorkflowScheduler $scheduler */
        $scheduler = $this->container->get(WorkflowScheduler::class);
        $scheduler->schedule();
    }

    public function deactivate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var WorkflowScheduler $scheduler */
        $scheduler = $this->container->get(WorkflowScheduler::class);
        $scheduler->unschedule();
    }

    public function uninstall(): void
    {
        global $wpdb;

        // $table is built exclusively from this module's own fixed,
        // compile-time logical names via SchemaBuilder::tableName() — the
        // identical helper every migration in this module uses to create
        // these exact tables — never from request/user input. DROP TABLE's
        // identifier cannot be parameterized via $wpdb->prepare()
        // placeholders (MySQL DDL has no bound-identifier support), so
        // backtick-quoting a value from this fully-trusted, zero-external-
        // input source is the correct construction here, not a shortcut.
        // The three DirectQuery/NoCaching/SchemaChange warnings are the
        // expected, unavoidable shape of any DROP TABLE — left active
        // rather than suppressed, per the module's own standing policy of
        // keeping WordPress.DB.* sniffs on.
        foreach (['workflow_approvals', 'workflow_step_results', 'workflow_runs', 'workflow_definitions'] as $logicalName) {
            $table = SchemaBuilder::tableName($logicalName);
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see justification above; DDL identifiers cannot use prepare() placeholders.
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}

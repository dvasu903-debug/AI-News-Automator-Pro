<?php

declare(strict_types=1);

namespace AINewsAutomator\Research;

use AINewsAutomator\AI\Manager\AIManager;
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
use AINewsAutomator\Research\Admin\ResearchSettingsPage;
use AINewsAutomator\Research\Api\ResearchSessionController;
use AINewsAutomator\Research\Authorization\ResearchAbilityPolicy;
use AINewsAutomator\Research\Clustering\HeuristicTopicClusterer;
use AINewsAutomator\Research\Contracts\CitationRepositoryInterface;
use AINewsAutomator\Research\Contracts\ClaimExtractorInterface;
use AINewsAutomator\Research\Contracts\ClaimRepositoryInterface;
use AINewsAutomator\Research\Contracts\ContradictionDetectorInterface;
use AINewsAutomator\Research\Contracts\ContradictionRepositoryInterface;
use AINewsAutomator\Research\Contracts\EntityExtractorInterface;
use AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface;
use AINewsAutomator\Research\Contracts\ExtractedEntityRepositoryInterface;
use AINewsAutomator\Research\Contracts\ResearchConfidenceInterface;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Contracts\SourceDiversityAnalyzerInterface;
use AINewsAutomator\Research\Contracts\TimelineBuilderInterface;
use AINewsAutomator\Research\Contracts\TopicClustererInterface;
use AINewsAutomator\Research\Contradiction\AiContradictionDetector;
use AINewsAutomator\Research\Diversity\SourceDiversityAnalyzer;
use AINewsAutomator\Research\Extraction\AiClaimExtractor;
use AINewsAutomator\Research\Extraction\AiEntityExtractor;
use AINewsAutomator\Research\Health\ResearchHealthCheck;
use AINewsAutomator\Research\Repositories\CitationRepository;
use AINewsAutomator\Research\Repositories\ClaimRepository;
use AINewsAutomator\Research\Repositories\ContradictionRepository;
use AINewsAutomator\Research\Repositories\EvidenceRepository;
use AINewsAutomator\Research\Repositories\ExtractedEntityRepository;
use AINewsAutomator\Research\Repositories\SessionRepository;
use AINewsAutomator\Research\Scoring\CompositeConfidenceScorer;
use AINewsAutomator\Research\Session\ResearchSessionManager;
use AINewsAutomator\Research\Session\ResearchSummaryBuilder;
use AINewsAutomator\Research\Storage\ResearchMigrationManifest;
use AINewsAutomator\Research\Timeline\TimelineBuilder;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Migrations\MigrationRunner;

/**
 * The Research module's single service provider.
 *
 * Structural enforcement of the approved boundaries (verified in the
 * Architecture Verification Report, not just asserted here): this class,
 * and every class it wires, has ZERO dependency on
 * Storage\Contracts\ArticleRepositoryInterface, ZERO dependency on any
 * Sources\* class, and ZERO call to wp_insert_post/wp_update_post.
 * Research produces a ResearchSummary and stops there.
 */
final class ResearchServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    private ?ContainerInterface $container = null;

    public function register(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->registerRepositories($container);
        $this->registerAnalysisServices($container);
        $this->registerExtractionServices($container);
        $this->registerSessionManager($container);
        $this->registerAuthorization($container);
        $this->registerHealthAndAdmin($container);
        $this->registerApi($container);
    }

    private function registerRepositories(ContainerInterface $container): void
    {
        $container->singleton(
            EvidenceRepositoryInterface::class,
            static fn (ContainerInterface $c): EvidenceRepositoryInterface => new EvidenceRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            ClaimRepositoryInterface::class,
            static fn (ContainerInterface $c): ClaimRepositoryInterface => new ClaimRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            ExtractedEntityRepositoryInterface::class,
            static fn (ContainerInterface $c): ExtractedEntityRepositoryInterface => new ExtractedEntityRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            CitationRepositoryInterface::class,
            static fn (ContainerInterface $c): CitationRepositoryInterface => new CitationRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            ContradictionRepositoryInterface::class,
            static fn (ContainerInterface $c): ContradictionRepositoryInterface => new ContradictionRepository($c->get(ConnectionInterface::class))
        );

        $container->singleton(
            ResearchSummaryBuilder::class,
            static fn (ContainerInterface $c): ResearchSummaryBuilder => new ResearchSummaryBuilder(
                $c->get(ClaimRepositoryInterface::class),
                $c->get(CitationRepositoryInterface::class),
                $c->get(ExtractedEntityRepositoryInterface::class),
                $c->get(ContradictionRepositoryInterface::class),
                $c->get(EvidenceRepositoryInterface::class),
                $c->get(SourceDiversityAnalyzerInterface::class),
                $c->get(TimelineBuilderInterface::class)
            )
        );

        $container->singleton(
            SessionRepositoryInterface::class,
            static fn (ContainerInterface $c): SessionRepositoryInterface => new SessionRepository(
                $c->get(ConnectionInterface::class),
                $c->get(ResearchSummaryBuilder::class)
            )
        );
    }

    private function registerAnalysisServices(ContainerInterface $container): void
    {
        $container->singleton(SourceDiversityAnalyzerInterface::class, static fn (): SourceDiversityAnalyzerInterface => new SourceDiversityAnalyzer());

        $container->singleton(
            TimelineBuilderInterface::class,
            static fn (ContainerInterface $c): TimelineBuilderInterface => new TimelineBuilder($c->get(EvidenceRepositoryInterface::class))
        );

        $container->singleton(ResearchConfidenceInterface::class, static fn (): ResearchConfidenceInterface => new CompositeConfidenceScorer());

        $container->singleton(TopicClustererInterface::class, static fn (): TopicClustererInterface => new HeuristicTopicClusterer());
    }

    private function registerExtractionServices(ContainerInterface $container): void
    {
        $container->singleton(
            ClaimExtractorInterface::class,
            static fn (ContainerInterface $c): ClaimExtractorInterface => new AiClaimExtractor(
                $c->get(AIManager::class),
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class)
            )
        );

        $container->singleton(
            EntityExtractorInterface::class,
            static fn (ContainerInterface $c): EntityExtractorInterface => new AiEntityExtractor(
                $c->get(AIManager::class),
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class)
            )
        );

        $container->singleton(
            ContradictionDetectorInterface::class,
            static fn (ContainerInterface $c): ContradictionDetectorInterface => new AiContradictionDetector(
                $c->get(AIManager::class),
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class)
            )
        );
    }

    private function registerSessionManager(ContainerInterface $container): void
    {
        $container->singleton(
            ResearchSessionManager::class,
            static fn (ContainerInterface $c): ResearchSessionManager => new ResearchSessionManager(
                $c->get(SessionRepositoryInterface::class),
                $c->get(EvidenceRepositoryInterface::class),
                $c->get(ClaimRepositoryInterface::class),
                $c->get(CitationRepositoryInterface::class),
                $c->get(ExtractedEntityRepositoryInterface::class),
                $c->get(ContradictionRepositoryInterface::class),
                $c->get(ClaimExtractorInterface::class),
                $c->get(EntityExtractorInterface::class),
                $c->get(ContradictionDetectorInterface::class),
                $c->get(ResearchConfidenceInterface::class),
                $c->get(TopicClustererInterface::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(EventMetadataFactory::class),
                $c->get(MetricsRepositoryInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(CorrelationContext::class)
            )
        );
    }

    private function registerAuthorization(ContainerInterface $container): void
    {
        $container->singleton(ResearchAbilityPolicy::class, static fn (): ResearchAbilityPolicy => new ResearchAbilityPolicy());
        $container->tag(ResearchAbilityPolicy::class, 'security.policies');
    }

    private function registerHealthAndAdmin(ContainerInterface $container): void
    {
        $container->singleton(
            ResearchHealthCheck::class,
            static fn (ContainerInterface $c): ResearchHealthCheck => new ResearchHealthCheck($c->get(SessionRepositoryInterface::class))
        );

        $container->singleton(
            ResearchSettingsPage::class,
            static fn (ContainerInterface $c): ResearchSettingsPage => new ResearchSettingsPage(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(SessionRepositoryInterface::class),
                $c->get(ResearchHealthCheck::class)
            )
        );
    }

    private function registerApi(ContainerInterface $container): void
    {
        $container->singleton(
            ResearchSessionController::class,
            static fn (ContainerInterface $c): ResearchSessionController => new ResearchSessionController(
                $c->get(ConfigRepositoryInterface::class),
                $c->get(RestSecurityMiddleware::class),
                $c->get(SessionRepositoryInterface::class),
                $c->get(ResearchSessionManager::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
        /** @var RestApiRegistry $restApi */
        $restApi = $container->get(RestApiRegistry::class);
        $restApi->register(ResearchSessionController::class);

        /** @var SettingsRegistry $settings */
        $settings = $container->get(SettingsRegistry::class);
        $settings->register(ResearchSettingsPage::class);

        // Automatic upgrade detection for Research's own tables — same
        // pattern as Storage/AI/Sources' own boot(), reusing Storage's
        // MigrationRunner singleton with Research's own manifest.
        add_action('plugins_loaded', static function () use ($container): void {
            /** @var MigrationRunner $runner */
            $runner = $container->get(MigrationRunner::class);
            $migrations = ResearchMigrationManifest::migrations();

            if ($runner->hasPending($migrations)) {
                $runner->migrate($migrations);
            }
        }, 8); // After Storage's (5), AI's (6), Sources' (7) own checks.
    }

    public function activate(): void
    {
        if ($this->container === null) {
            return;
        }

        /** @var MigrationRunner $runner */
        $runner = $this->container->get(MigrationRunner::class);
        $runner->migrate(ResearchMigrationManifest::migrations());
    }

    public function deactivate(): void
    {
        // Reversible — no data destroyed on plain deactivation.
    }

    public function uninstall(): void
    {
        global $wpdb;

        $tables = [
            'research_contradictions',
            'research_citations',
            'research_entities',
            'research_claim_evidence',
            'research_claims',
            'research_evidence',
            'research_sessions',
        ];

        foreach ($tables as $logical) {
            $table = $wpdb->prefix . 'ana_' . $logical;
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}

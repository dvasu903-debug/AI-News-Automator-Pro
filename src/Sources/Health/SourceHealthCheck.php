<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Health;

use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;
use AINewsAutomator\Sources\Contracts\SourceConnectorRegistryInterface;
use AINewsAutomator\Sources\Contracts\SourceReputationInterface;
use AINewsAutomator\Storage\Contracts\SourceRepositoryInterface;

/**
 * Reuses Security's HealthCheckResult shape — the 5th module to do so
 * (Storage and AI were the 3rd/4th), keeping every module's diagnostics
 * page rendering consistently.
 */
final class SourceHealthCheck
{
    private const DOCS_BASE = 'https://example.com/ai-news-automator/docs/sources#';
    private const STALE_THRESHOLD_DAYS = 3;
    private const LOW_REPUTATION_THRESHOLD = 0.5;

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly SourceConnectorRegistryInterface $registry,
        private readonly SourceReputationInterface $reputation,
    ) {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkConnectorsRegistered(),
            ...$this->checkStaleAndLowReputationSources(),
        ];
    }

    private function checkConnectorsRegistered(): HealthCheckResult
    {
        $count = count($this->registry->all());

        if ($count === 0) {
            return new HealthCheckResult(
                'Source connectors',
                HealthStatus::Warning,
                'No source connectors are registered.',
                'This indicates a Sources module wiring problem, not a configuration issue — connectors register automatically.',
                false,
                self::DOCS_BASE . 'no-connectors'
            );
        }

        return new HealthCheckResult('Source connectors', HealthStatus::Ok, sprintf('%d connector type(s) registered.', $count));
    }

    /**
     * @return list<HealthCheckResult>
     */
    private function checkStaleAndLowReputationSources(): array
    {
        $page = $this->sources->paginate(1, 100, null, true); // enabled sources only
        $results = [];
        $staleCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . self::STALE_THRESHOLD_DAYS . ' days');

        foreach ($page->items as $source) {
            if ($source->lastFetchedAt !== null && $source->lastFetchedAt < $staleCutoff) {
                $results[] = new HealthCheckResult(
                    sprintf('Source: %s', $source->name),
                    HealthStatus::Warning,
                    sprintf('No successful fetch in over %d days.', self::STALE_THRESHOLD_DAYS),
                    'Check the source\'s configuration and recent error via the Sources admin page.',
                    false,
                    self::DOCS_BASE . 'stale-source'
                );
            }

            if ($source->id !== null) {
                $score = $this->reputation->scoreFor($source->id);
                if ($score < self::LOW_REPUTATION_THRESHOLD) {
                    $results[] = new HealthCheckResult(
                        sprintf('Source: %s', $source->name),
                        HealthStatus::Warning,
                        sprintf('Low reputation score (%.0f%% success rate).', $score * 100),
                        'Repeated fetch failures — verify the source URL is still valid.',
                        false,
                        self::DOCS_BASE . 'low-reputation'
                    );
                }
            }
        }

        return $results;
    }
}

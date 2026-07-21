<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Health;

use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;

/**
 * Reuses Security's HealthCheckResult shape — the 6th module to do so.
 */
final class ResearchHealthCheck
{
    private const DOCS_BASE = 'https://example.com/ai-news-automator/docs/research#';
    private const STUCK_ANALYZING_THRESHOLD = 5;

    public function __construct(private readonly SessionRepositoryInterface $sessions)
    {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkStuckSessions(),
        ];
    }

    private function checkStuckSessions(): HealthCheckResult
    {
        // Sessions stuck in "analyzing" (e.g. a crashed process mid-pass)
        // are a real operational signal — analyzeSession() is meant to
        // complete synchronously; a long-lived "analyzing" row usually
        // means something interrupted it.
        $stuck = $this->sessions->byStatus(SessionStatus::Analyzing, self::STUCK_ANALYZING_THRESHOLD + 1);

        if (count($stuck) === 0) {
            return new HealthCheckResult('Research sessions', HealthStatus::Ok, 'No sessions stuck in analysis.');
        }

        if (count($stuck) > self::STUCK_ANALYZING_THRESHOLD) {
            return new HealthCheckResult(
                'Research sessions',
                HealthStatus::Warning,
                sprintf('%d session(s) appear stuck in "analyzing" status.', count($stuck)),
                'These likely represent an interrupted analysis pass — review and consider abandoning stale sessions.',
                false,
                self::DOCS_BASE . 'stuck-sessions'
            );
        }

        return new HealthCheckResult('Research sessions', HealthStatus::Ok, sprintf('%d session(s) currently analyzing.', count($stuck)));
    }
}

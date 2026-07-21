<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Health;

use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;
use AINewsAutomator\Workflow\Contracts\WorkflowRunRepositoryInterface;
use AINewsAutomator\Workflow\Entities\WorkflowRunStatus;

/**
 * Reuses Security's HealthCheckResult shape — the 7th module to do so.
 */
final class WorkflowHealthCheck
{
    private const DOCS_BASE = 'https://example.com/ai-news-automator/docs/workflow#';
    private const STUCK_RUNNING_THRESHOLD = 5;

    public function __construct(private readonly WorkflowRunRepositoryInterface $runs)
    {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkStuckRuns(),
        ];
    }

    private function checkStuckRuns(): HealthCheckResult
    {
        // A run stuck in "running" (not deferred, not awaiting approval —
        // those are expected pauses) usually means a synchronous step
        // threw outside the Runner's own try/catch, or a crashed
        // request left the run mid-walk. This is the Workflow-module
        // analogue of Research's "stuck in analyzing" check.
        $stuck = $this->runs->byStatus(WorkflowRunStatus::Running, self::STUCK_RUNNING_THRESHOLD + 1);

        if (count($stuck) === 0) {
            return new HealthCheckResult('Workflow runs', HealthStatus::Ok, 'No runs stuck in "running" status.');
        }

        if (count($stuck) > self::STUCK_RUNNING_THRESHOLD) {
            return new HealthCheckResult(
                'Workflow runs',
                HealthStatus::Warning,
                sprintf('%d run(s) appear stuck in "running" status.', count($stuck)),
                'Investigate stuck runs — a synchronous step may have thrown outside expected error handling.',
                false,
                self::DOCS_BASE . 'stuck-runs'
            );
        }

        return new HealthCheckResult('Workflow runs', HealthStatus::Ok, sprintf('%d run(s) currently running (within normal range).', count($stuck)));
    }
}

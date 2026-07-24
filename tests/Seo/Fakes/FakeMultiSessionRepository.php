<?php
/**
 * A SessionRepositoryInterface test double supporting multiple,
 * independently-configured sessions — InternalLinkSuggesterTest needs
 * to summarize() several different session ids in one scenario, unlike
 * Publishing's own single-session FakeSessionRepository.
 *
 * @package AINewsAutomator\Tests\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Fakes;

use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Exceptions\SessionStateException;

final class FakeMultiSessionRepository implements SessionRepositoryInterface
{
    /** @var array<int, ResearchSummary> */
    private array $summaries = [];

    public function seed(int $sessionId, ResearchSummary $summary): void
    {
        $this->summaries[$sessionId] = $summary;
    }

    public function find(int $id): ?ResearchSession
    {
        return null;
    }

    public function findByCorrelationId(string $correlationId): ?ResearchSession
    {
        return null;
    }

    public function save(ResearchSession $session): int
    {
        return 0;
    }

    public function byStatus(SessionStatus $status, int $limit = 25): array
    {
        return [];
    }

    public function summarize(int $sessionId): ResearchSummary
    {
        if (!isset($this->summaries[$sessionId])) {
            throw SessionStateException::notGathering($sessionId, 'unknown');
        }

        return $this->summaries[$sessionId];
    }
}

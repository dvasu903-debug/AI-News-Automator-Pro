<?php
/**
 * Shared test double for Research\Contracts\SessionRepositoryInterface,
 * used by ResearchEditorialPolicyTest and GenerateActionTest. Only
 * summarize() has real test-configurable behavior — the other methods
 * are outside what those tests exercise.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;

final class FakeSessionRepository implements SessionRepositoryInterface
{
    public ?ResearchSummary $summarizeReturn = null;
    public ?\Throwable $summarizeThrows = null;

    /** @var list<int> */
    public array $summarizeCalls = [];

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
        $this->summarizeCalls[] = $sessionId;

        if (null !== $this->summarizeThrows) {
            throw $this->summarizeThrows;
        }

        if (null === $this->summarizeReturn) {
            throw new \LogicException('FakeSessionRepository::summarizeReturn was not configured by the test.');
        }

        return $this->summarizeReturn;
    }
}

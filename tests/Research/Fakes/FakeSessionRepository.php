<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Exceptions\SessionStateException;
use AINewsAutomator\Research\Session\ResearchSummaryBuilder;

final class FakeSessionRepository implements SessionRepositoryInterface
{
    /** @var array<int, ResearchSession> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly ?ResearchSummaryBuilder $summaryBuilder = null)
    {
    }

    public function find(int $id): ?ResearchSession
    {
        return $this->rows[$id] ?? null;
    }

    public function findByCorrelationId(string $correlationId): ?ResearchSession
    {
        foreach ($this->rows as $row) {
            if ($row->correlationId === $correlationId) {
                return $row;
            }
        }
        return null;
    }

    public function save(ResearchSession $session): int
    {
        $id = $session->id ?? $this->nextId++;
        $saved = $session->id === null
            ? new ResearchSession($id, $session->correlationId, $session->topic, $session->vertical, $session->status, $session->topicCluster, $session->confidenceScore, $session->createdAt, $session->updatedAt, $session->completedAt)
            : $session;
        $this->rows[$id] = $saved;
        return $id;
    }

    public function byStatus(SessionStatus $status, int $limit = 25): array
    {
        return array_values(array_slice(array_filter($this->rows, static fn (ResearchSession $s): bool => $s->status === $status), 0, $limit));
    }

    public function summarize(int $sessionId): ResearchSummary
    {
        $session = $this->find($sessionId);
        if ($session === null) {
            throw new SessionStateException("Session {$sessionId} not found.");
        }
        if ($session->status !== SessionStatus::Completed) {
            throw SessionStateException::notGathering($sessionId, $session->status->value);
        }
        if ($this->summaryBuilder === null) {
            throw new \LogicException('FakeSessionRepository built without a summary builder cannot summarize.');
        }
        return $this->summaryBuilder->build($session);
    }
}

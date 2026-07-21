<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\DTO\ResearchSummary;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;

/**
 * Persists ResearchSession records and assembles the authoritative
 * ResearchSummary via summarize() — see that method's own docblock for
 * why it is the one sanctioned read path for consumers of research output.
 */
interface SessionRepositoryInterface
{
    public function find(int $id): ?ResearchSession;

    public function findByCorrelationId(string $correlationId): ?ResearchSession;

    public function save(ResearchSession $session): int;

    /**
     * @return list<ResearchSession>
     */
    public function byStatus(SessionStatus $status, int $limit = 25): array;

    /**
     * Assembles the authoritative ResearchSummary for a session — the
     * ONE read path Publishing (Module 8, future) is meant to depend on.
     * Never returns partial/in-progress data as if it were final: throws
     * if the session is not yet Completed.
     *
     * @throws \AINewsAutomator\Research\Exceptions\SessionStateException
     */
    public function summarize(int $sessionId): ResearchSummary;
}

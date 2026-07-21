<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\Contradiction;

/**
 * Persists detected contradictions between claims within a session.
 */
interface ContradictionRepositoryInterface
{
    public function record(Contradiction $contradiction): int;

    /**
     * An explicit researcher/editor action — this module never resolves
     * a contradiction automatically.
     */
    public function resolve(int $contradictionId): void;

    /**
     * @return list<Contradiction>
     */
    public function forSession(int $sessionId, bool $unresolvedOnly = false): array;
}

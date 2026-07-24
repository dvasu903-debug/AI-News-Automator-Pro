<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\ContradictionRepositoryInterface;
use AINewsAutomator\Research\Entities\Contradiction;

final class FakeContradictionRepository implements ContradictionRepositoryInterface
{
    /** @var array<int, Contradiction> */
    public array $rows = [];
    private int $nextId = 1;

    public function record(Contradiction $contradiction): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = new Contradiction($id, $contradiction->sessionId, $contradiction->claimAId, $contradiction->claimBId, $contradiction->description, $contradiction->severity, false, $contradiction->createdAt);
        return $id;
    }

    public function resolve(int $contradictionId): void
    {
        $c = $this->rows[$contradictionId];
        $this->rows[$contradictionId] = $c->withResolved(true);
    }

    public function forSession(int $sessionId, bool $unresolvedOnly = false): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (Contradiction $c): bool => $c->sessionId === $sessionId && (!$unresolvedOnly || !$c->resolved)
        ));
    }
}

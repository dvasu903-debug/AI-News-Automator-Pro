<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\CitationRepositoryInterface;
use AINewsAutomator\Research\Entities\Citation;

final class FakeCitationRepository implements CitationRepositoryInterface
{
    /** @var list<Citation> */
    public array $rows = [];
    private int $nextId = 1;

    public function record(Citation $citation): int
    {
        $id = $this->nextId++;
        $this->rows[] = new Citation($id, $citation->claimId, $citation->evidenceId, $citation->citationText, $citation->createdAt);
        return $id;
    }

    public function forClaim(int $claimId): array
    {
        return array_values(array_filter($this->rows, static fn (Citation $c): bool => $c->claimId === $claimId));
    }

    public function forSession(int $sessionId): array
    {
        return $this->rows;
    }
}

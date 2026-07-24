<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\EvidenceRepositoryInterface;
use AINewsAutomator\Research\Entities\Evidence;

final class FakeEvidenceRepository implements EvidenceRepositoryInterface
{
    /** @var list<Evidence> */
    public array $rows = [];
    private int $nextId = 1;

    public function record(Evidence $evidence): int
    {
        $id = $this->nextId++;
        $this->rows[] = new Evidence(
            $id, $evidence->sessionId, $evidence->sourceUrl, $evidence->sourceType,
            $evidence->domain, $evidence->credibilityScore, $evidence->snippet,
            $evidence->publishedAt, $evidence->createdAt
        );
        return $id;
    }

    public function forSession(int $sessionId): array
    {
        return array_values(array_filter($this->rows, static fn (Evidence $e): bool => $e->sessionId === $sessionId));
    }

    public function find(int $id): ?Evidence
    {
        foreach ($this->rows as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }
        return null;
    }
}

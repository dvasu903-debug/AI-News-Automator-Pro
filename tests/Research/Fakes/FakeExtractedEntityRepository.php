<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\ExtractedEntityRepositoryInterface;
use AINewsAutomator\Research\Entities\ExtractedEntity;
use AINewsAutomator\Storage\Entities\EntityDates;

final class FakeExtractedEntityRepository implements ExtractedEntityRepositoryInterface
{
    /** @var array<int, ExtractedEntity> */
    public array $rows = [];
    private int $nextId = 1;

    public function recordOrIncrement(int $sessionId, string $name, string $entityType): int
    {
        foreach ($this->rows as $id => $entity) {
            if ($entity->sessionId === $sessionId && $entity->name === $name && $entity->entityType === $entityType) {
                $this->rows[$id] = $entity->withIncrementedMentionCount();
                return $id;
            }
        }
        $id = $this->nextId++;
        $this->rows[$id] = new ExtractedEntity($id, $sessionId, $name, $entityType, 1, EntityDates::now());
        return $id;
    }

    public function forSession(int $sessionId): array
    {
        return array_values(array_filter($this->rows, static fn (ExtractedEntity $e): bool => $e->sessionId === $sessionId));
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\ExtractedEntity;

/**
 * Persists named entities extracted during research, with automatic
 * mention-count tracking per (session, name, type).
 */
interface ExtractedEntityRepositoryInterface
{
    /**
     * Records a new entity, or increments mention_count if (session_id,
     * name, entity_type) already exists for this session.
     */
    public function recordOrIncrement(int $sessionId, string $name, string $entityType): int;

    /**
     * @return list<ExtractedEntity>
     */
    public function forSession(int $sessionId): array;
}

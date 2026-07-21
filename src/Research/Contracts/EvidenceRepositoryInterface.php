<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\Evidence;

/**
 * No update() method is exposed anywhere on this interface — evidence is
 * immutable once recorded (the "immutable provenance" requirement).
 */
interface EvidenceRepositoryInterface
{
    public function record(Evidence $evidence): int;

    /**
     * @return list<Evidence>
     */
    public function forSession(int $sessionId): array;

    public function find(int $id): ?Evidence;
}

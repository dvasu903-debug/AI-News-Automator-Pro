<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contracts;

use AINewsAutomator\Research\Entities\ExtractedEntity;

/**
 * Suggests a topic-cluster label for a session from its extracted
 * entities, so related sessions can be grouped for reporting.
 */
interface TopicClustererInterface
{
    /**
     * Suggests a topic-cluster label for a session from its extracted
     * entities — sessions sharing a cluster label are considered related
     * for reporting/grouping purposes. Returns null if no confident
     * cluster label can be determined (e.g. too few entities).
     *
     * @param list<ExtractedEntity> $entities
     */
    public function clusterFor(string $topic, array $entities): ?string;
}

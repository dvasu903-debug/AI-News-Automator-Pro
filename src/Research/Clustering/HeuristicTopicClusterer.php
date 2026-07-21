<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Clustering;

use AINewsAutomator\Research\Contracts\TopicClustererInterface;

/**
 * Deterministic — derives a cluster label from the session's most-
 * mentioned entity, not another AI call. Sessions sharing a cluster
 * label (e.g. the same organization or event name, slugified) are
 * considered related for reporting/grouping. A dedicated AI-assisted
 * clustering pass is a reasonable future enhancement but not justified
 * as a default cost for every session given a cheap, decent heuristic
 * exists.
 */
final class HeuristicTopicClusterer implements TopicClustererInterface
{
    private const MIN_ENTITIES_FOR_CLUSTERING = 2;
    private const MIN_MENTIONS_FOR_CLUSTERING = 2;

    public function clusterFor(string $topic, array $entities): ?string
    {
        if (count($entities) < self::MIN_ENTITIES_FOR_CLUSTERING) {
            return null;
        }

        $mostMentioned = null;

        foreach ($entities as $entity) {
            if ($mostMentioned === null || $entity->mentionCount > $mostMentioned->mentionCount) {
                $mostMentioned = $entity;
            }
        }

        if ($mostMentioned === null || $mostMentioned->mentionCount < self::MIN_MENTIONS_FOR_CLUSTERING) {
            return null;
        }

        return $this->slugify($mostMentioned->name);
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}

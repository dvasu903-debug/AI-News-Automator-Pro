<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research;

use AINewsAutomator\Research\Clustering\HeuristicTopicClusterer;
use AINewsAutomator\Research\Entities\ExtractedEntity;
use AINewsAutomator\Storage\Entities\EntityDates;
use PHPUnit\Framework\TestCase;

final class HeuristicTopicClustererTest extends TestCase
{
    private function entity(string $name, int $mentions): ExtractedEntity
    {
        return new ExtractedEntity(null, 1, $name, 'organization', $mentions, EntityDates::now());
    }

    public function test_too_few_entities_returns_null(): void
    {
        $clusterer = new HeuristicTopicClusterer();
        $result = $clusterer->clusterFor('Some Topic', [$this->entity('Acme Corp', 5)]);

        $this->assertNull($result);
    }

    public function test_low_mention_count_returns_null(): void
    {
        $clusterer = new HeuristicTopicClusterer();
        $result = $clusterer->clusterFor('Some Topic', [
            $this->entity('Acme Corp', 1),
            $this->entity('Widget Inc', 1),
        ]);

        $this->assertNull($result);
    }

    public function test_returns_slug_of_most_mentioned_entity(): void
    {
        $clusterer = new HeuristicTopicClusterer();
        $result = $clusterer->clusterFor('Some Topic', [
            $this->entity('Acme Corp', 2),
            $this->entity('Widget Inc', 5),
        ]);

        $this->assertSame('widget-inc', $result);
    }

    public function test_slug_strips_special_characters(): void
    {
        $clusterer = new HeuristicTopicClusterer();
        $result = $clusterer->clusterFor('Some Topic', [
            $this->entity("O'Brien & Sons, LLC.", 3),
            $this->entity('Other', 1),
        ]);

        $this->assertSame('o-brien-sons-llc', $result);
    }
}

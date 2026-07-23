<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Research\Fakes;

use AINewsAutomator\Research\Contracts\TopicClustererInterface;

final class FakeTopicClusterer implements TopicClustererInterface
{
    public function __construct(private readonly ?string $fixedCluster = null)
    {
    }

    public function clusterFor(string $topic, array $entities): ?string
    {
        return $this->fixedCluster;
    }
}

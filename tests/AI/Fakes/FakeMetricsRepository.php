<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI\Fakes;

use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;

final class FakeMetricsRepository implements MetricsRepositoryInterface
{
    /** @var array<string, int> */
    public array $counters = [];

    /** @var list<array{key: string, value: int, dimensions: array<string, mixed>}> */
    public array $events = [];

    public function increment(string $metricKey, int $by = 1, array $dimensions = []): void
    {
        $this->counters[$metricKey] = ($this->counters[$metricKey] ?? 0) + $by;
    }

    public function counterValue(string $metricKey, array $dimensions = []): int
    {
        return $this->counters[$metricKey] ?? 0;
    }

    public function allCounters(): array
    {
        return $this->counters;
    }

    public function record(string $metricKey, int $value, array $dimensions = []): void
    {
        $this->events[] = ['key' => $metricKey, 'value' => $value, 'dimensions' => $dimensions];
    }

    public function aggregateHourly(string $metricKey, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return [];
    }
}

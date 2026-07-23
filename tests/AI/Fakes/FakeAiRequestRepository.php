<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI\Fakes;

use AINewsAutomator\Storage\Contracts\AiRequestRepositoryInterface;
use AINewsAutomator\Storage\Entities\AiRequestRecord;
use AINewsAutomator\Storage\Query\PageResult;

/**
 * In-memory fake for the Storage repository AIManager records every
 * request through. AIManager's orchestration logic is what these tests
 * exercise — real persistence correctness is Module 3's own test
 * responsibility, already covered there.
 */
final class FakeAiRequestRepository implements AiRequestRepositoryInterface
{
    /** @var list<AiRequestRecord> */
    public array $recorded = [];

    public function record(AiRequestRecord $request): int
    {
        $this->recorded[] = $request;
        return count($this->recorded);
    }

    public function costSince(string $provider, \DateTimeImmutable $since): int
    {
        return array_sum(array_map(
            static fn (AiRequestRecord $r): int => $r->costCents ?? 0,
            array_filter($this->recorded, static fn (AiRequestRecord $r): bool => $r->provider === $provider)
        ));
    }

    public function paginate(int $page = 1, int $perPage = 25, ?string $provider = null, ?string $correlationId = null): PageResult
    {
        return new PageResult($this->recorded, $page, $perPage, count($this->recorded), 1, null);
    }
}

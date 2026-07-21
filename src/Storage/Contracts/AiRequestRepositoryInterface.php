<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\AiRequestRecord;
use AINewsAutomator\Storage\Query\PageResult;

interface AiRequestRepositoryInterface
{
    public function record(AiRequestRecord $request): int;

    public function costSince(string $provider, \DateTimeImmutable $since): int;

    /**
     * @return PageResult<AiRequestRecord>
     */
    public function paginate(int $page = 1, int $perPage = 25, ?string $provider = null, ?string $correlationId = null): PageResult;
}

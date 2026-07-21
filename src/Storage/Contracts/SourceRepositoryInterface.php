<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\SourceRecord;
use AINewsAutomator\Storage\Query\PageResult;

interface SourceRepositoryInterface
{
    public function find(int $id): ?SourceRecord;

    /**
     * @return PageResult<SourceRecord>
     */
    public function paginate(int $page = 1, int $perPage = 25, ?string $type = null, ?bool $enabled = null): PageResult;

    /** Returns the saved record's id (new or existing). */
    public function save(SourceRecord $source): int;

    public function delete(int $id): bool;

    /**
     * @return list<SourceRecord> Enabled sources not fetched within their cadence.
     */
    public function dueForFetch(): array;

    public function recordFetchResult(int $id, bool $success, ?string $error = null): void;
}

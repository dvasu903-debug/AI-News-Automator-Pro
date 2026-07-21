<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

use AINewsAutomator\Storage\Entities\LogEntry;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\PageResult;

/**
 * Persistence for `ana_logs`. This is the storage-layer contract
 * TableBackedLogger (which implements Core's LoggerInterface) delegates
 * to — separating "the logging API everyone calls" from "how logs are
 * persisted", the same pattern used for Audit.
 */
interface LogRepositoryInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function persist(string $level, string $message, array $context, ?string $correlationId): void;

    /**
     * @return list<LogEntry>
     */
    public function recent(int $limit = 50): array;

    /**
     * @param list<Filter> $filters
     * @return PageResult<LogEntry>
     */
    public function paginate(array $filters, int $page = 1, int $perPage = 50): PageResult;

    public function purgeOlderThan(int $days): int;
}

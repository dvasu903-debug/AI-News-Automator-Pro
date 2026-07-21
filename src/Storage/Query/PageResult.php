<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Query;

/**
 * The result of a paginated query: the items for this page, plus enough
 * metadata to render pagination controls. `total`/`totalPages` are null
 * when produced by simplePaginate() (which deliberately skips the
 * COUNT(*) query) — callers that used simplePaginate() get `hasMore`
 * instead, which is cheap (a LIMIT+1 peek).
 *
 * @template T
 */
final class PageResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $perPage,
        public readonly ?int $total = null,
        public readonly ?int $totalPages = null,
        public readonly ?bool $hasMore = null,
    ) {
    }
}

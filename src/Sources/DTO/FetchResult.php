<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\DTO;

/**
 * The result of one connector's fetch() call: a list of discovered items
 * (empty is valid — a feed with no new items since the last sync is a
 * success, not a failure) plus status and, on failure, an error detail.
 */
final class FetchResult
{
    /**
     * @param list<NormalizedItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly FetchStatus $status,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function success(array $items): self
    {
        return new self($items, FetchStatus::Success);
    }

    public static function failed(string $errorMessage): self
    {
        return new self([], FetchStatus::Failed, $errorMessage);
    }
}

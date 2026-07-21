<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Query;

/**
 * A single ORDER BY clause: column + direction.
 */
final class SortOrder
{
    public function __construct(
        public readonly string $column,
        public readonly SortDirection $direction = SortDirection::Descending,
    ) {
    }

    public static function asc(string $column): self
    {
        return new self($column, SortDirection::Ascending);
    }

    public static function desc(string $column): self
    {
        return new self($column, SortDirection::Descending);
    }
}

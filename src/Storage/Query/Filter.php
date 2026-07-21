<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Query;

/**
 * A single WHERE condition: column, operator, value(s). Immutable value
 * object consumed by QueryBuilder. The column name is validated by
 * QueryBuilder against an allowlist derived from the query's own
 * select/known columns before being interpolated as an identifier —
 * values themselves are always bound as prepared-statement parameters,
 * never concatenated.
 */
final class Filter
{
    /**
     * @param mixed $value Scalar for most operators; array for In/NotIn/Between.
     */
    private function __construct(
        public readonly string $column,
        public readonly FilterOperator $operator,
        public readonly mixed $value = null,
    ) {
    }

    public static function equals(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::Equals, $value);
    }

    public static function notEquals(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::NotEquals, $value);
    }

    public static function greaterThan(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::GreaterThan, $value);
    }

    public static function greaterThanOrEqual(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::GreaterThanOrEqual, $value);
    }

    public static function lessThan(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::LessThan, $value);
    }

    public static function lessThanOrEqual(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::LessThanOrEqual, $value);
    }

    public static function like(string $column, string $value): self
    {
        return new self($column, FilterOperator::Like, $value);
    }

    /**
     * @param list<mixed> $values
     */
    public static function in(string $column, array $values): self
    {
        return new self($column, FilterOperator::In, $values);
    }

    /**
     * @param list<mixed> $values
     */
    public static function notIn(string $column, array $values): self
    {
        return new self($column, FilterOperator::NotIn, $values);
    }

    public static function between(string $column, mixed $low, mixed $high): self
    {
        return new self($column, FilterOperator::Between, [$low, $high]);
    }

    public static function isNull(string $column): self
    {
        return new self($column, FilterOperator::IsNull);
    }

    public static function isNotNull(string $column): self
    {
        return new self($column, FilterOperator::IsNotNull);
    }
}

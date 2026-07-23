<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\FilterOperator;
use AINewsAutomator\Storage\Query\PageResult;
use AINewsAutomator\Storage\Query\SortDirection;
use AINewsAutomator\Storage\Query\SortOrder;
use PHPUnit\Framework\TestCase;

final class QueryValueObjectsTest extends TestCase
{
    public function test_filter_named_constructors_set_correct_operator(): void
    {
        $this->assertSame(FilterOperator::Equals, Filter::equals('a', 1)->operator);
        $this->assertSame(FilterOperator::NotEquals, Filter::notEquals('a', 1)->operator);
        $this->assertSame(FilterOperator::GreaterThan, Filter::greaterThan('a', 1)->operator);
        $this->assertSame(FilterOperator::LessThanOrEqual, Filter::lessThanOrEqual('a', 1)->operator);
        $this->assertSame(FilterOperator::Like, Filter::like('a', 'x')->operator);
        $this->assertSame(FilterOperator::In, Filter::in('a', [1, 2])->operator);
        $this->assertSame(FilterOperator::Between, Filter::between('a', 1, 2)->operator);
        $this->assertSame(FilterOperator::IsNull, Filter::isNull('a')->operator);
    }

    public function test_between_filter_stores_low_and_high_as_value_array(): void
    {
        $filter = Filter::between('priority', 1, 10);
        $this->assertSame([1, 10], $filter->value);
    }

    public function test_sort_order_named_constructors(): void
    {
        $this->assertSame(SortDirection::Ascending, SortOrder::asc('created_at')->direction);
        $this->assertSame(SortDirection::Descending, SortOrder::desc('created_at')->direction);
    }

    public function test_page_result_full_pagination_shape(): void
    {
        $result = new PageResult(['a', 'b'], page: 2, perPage: 2, total: 10, totalPages: 5, hasMore: null);

        $this->assertSame(['a', 'b'], $result->items);
        $this->assertSame(2, $result->page);
        $this->assertSame(10, $result->total);
        $this->assertSame(5, $result->totalPages);
        $this->assertNull($result->hasMore);
    }

    public function test_page_result_simple_pagination_shape(): void
    {
        $result = new PageResult(['a'], page: 1, perPage: 1, total: null, totalPages: null, hasMore: true);

        $this->assertNull($result->total);
        $this->assertNull($result->totalPages);
        $this->assertTrue($result->hasMore);
    }
}

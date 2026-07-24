<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\QueryBuilderInterface;
use AINewsAutomator\Storage\Database\QueryBuilder;
use AINewsAutomator\Storage\Query\Filter;
use AINewsAutomator\Storage\Query\SortOrder;
use PHPUnit\Framework\TestCase;

/**
 * Tests QueryBuilder purely by inspecting toSql()'s output — no database
 * involved. This is possible because compose (QueryBuilder) and execute
 * (Connection) are separate classes, exactly the payoff described in the
 * approved Storage design.
 */
final class QueryBuilderTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        // toSql() never touches the connection, so a minimal stub suffices.
        $connection = new class implements ConnectionInterface {
            public function table(string $logicalName): string { return 'wp_ana_' . $logicalName; }
            public function newQuery(string $logicalTable): QueryBuilderInterface { throw new \LogicException('unused'); }
            public function select(string $sql, array $params = []): array { return []; }
            public function selectOne(string $sql, array $params = []): ?array { return null; }
            public function scalar(string $sql, array $params = []): mixed { return null; }
            public function insert(string $logicalTable, array $data): int { return 0; }
            public function insertMany(string $logicalTable, array $rows): int { return 0; }
            public function upsertIncrement(string $logicalTable, array $data, array $incrementColumns): void {}
            public function update(string $logicalTable, array $data, array $where): int { return 0; }
            public function delete(string $logicalTable, array $where): int { return 0; }
            public function statement(string $sql, array $params = []): int { return 0; }
            public function lastInsertId(): int { return 0; }
        };

        return new QueryBuilder($connection, 'wp_ana_queue');
    }

    public function test_select_all_no_conditions(): void
    {
        $result = $this->builder()->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue`', $result['sql']);
        $this->assertSame([], $result['params']);
    }

    public function test_single_equals_filter(): void
    {
        $result = $this->builder()->where(Filter::equals('status', 'pending'))->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `status` = %s', $result['sql']);
        $this->assertSame(['pending'], $result['params']);
    }

    public function test_multiple_filters_combine_with_and(): void
    {
        $result = $this->builder()
            ->whereAll([Filter::equals('status', 'pending'), Filter::greaterThan('priority', 50)])
            ->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `status` = %s AND `priority` > %d', $result['sql']);
        $this->assertSame(['pending', 50], $result['params']);
    }

    public function test_in_filter(): void
    {
        $result = $this->builder()->where(Filter::in('status', ['pending', 'processing']))->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `status` IN (%s, %s)', $result['sql']);
        $this->assertSame(['pending', 'processing'], $result['params']);
    }

    public function test_empty_in_filter_matches_nothing(): void
    {
        $result = $this->builder()->where(Filter::in('status', []))->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE 1 = 0', $result['sql']);
    }

    public function test_between_filter(): void
    {
        $result = $this->builder()->where(Filter::between('priority', 10, 20))->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `priority` BETWEEN %d AND %d', $result['sql']);
        $this->assertSame([10, 20], $result['params']);
    }

    public function test_is_null_and_is_not_null(): void
    {
        $nullResult = $this->builder()->where(Filter::isNull('worker'))->toSql();
        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `worker` IS NULL', $nullResult['sql']);
        $this->assertSame([], $nullResult['params']);

        $notNullResult = $this->builder()->where(Filter::isNotNull('worker'))->toSql();
        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `worker` IS NOT NULL', $notNullResult['sql']);
    }

    public function test_like_filter_escapes_wildcards(): void
    {
        $result = $this->builder()->where(Filter::like('name', '50%_off'))->toSql();

        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `name` LIKE %s', $result['sql']);
        $this->assertSame(['%50\\%\\_off%'], $result['params']);
    }

    public function test_order_by_and_limit_offset(): void
    {
        $result = $this->builder()
            ->orderBy(SortOrder::desc('priority'))
            ->orderBy(SortOrder::asc('created_at'))
            ->limit(10)
            ->offset(20)
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `wp_ana_queue` ORDER BY `priority` DESC, `created_at` ASC LIMIT %d OFFSET %d',
            $result['sql']
        );
        $this->assertSame([10, 20], $result['params']);
    }

    public function test_select_restricts_columns(): void
    {
        $result = $this->builder()->select(['id', 'status'])->toSql();

        $this->assertSame('SELECT `id`, `status` FROM `wp_ana_queue`', $result['sql']);
    }

    public function test_invalid_column_identifier_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->where(Filter::equals('status; DROP TABLE wp_ana_queue; --', 'x'));
    }

    public function test_invalid_select_column_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->select(['id, (SELECT 1)']);
    }

    public function test_builder_is_immutable_across_chained_calls(): void
    {
        $base = $this->builder();
        $filtered = $base->where(Filter::equals('status', 'pending'));

        // The original builder must remain unaffected by the chained call.
        $this->assertSame('SELECT * FROM `wp_ana_queue`', $base->toSql()['sql']);
        $this->assertSame('SELECT * FROM `wp_ana_queue` WHERE `status` = %s', $filtered->toSql()['sql']);
    }
}

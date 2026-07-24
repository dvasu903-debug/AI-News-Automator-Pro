<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\PurgeableInterface;
use AINewsAutomator\Storage\Contracts\QueryBuilderInterface;
use AINewsAutomator\Storage\Retention\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function test_purge_delegates_to_repository_and_returns_count(): void
    {
        $repository = new class implements PurgeableInterface {
            public int $calledWithDays = 0;
            public function purgeOlderThan(int $days): int
            {
                $this->calledWithDays = $days;
                return 42;
            }
        };

        $policy = new RetentionPolicy('logs', $repository, 30);

        $connection = $this->createStub(ConnectionInterface::class);
        $purged = $policy->purge($connection);

        $this->assertSame(42, $purged);
        $this->assertSame(30, $repository->calledWithDays);
    }

    public function test_exposes_table_and_retention_days(): void
    {
        $repository = new class implements PurgeableInterface {
            public function purgeOlderThan(int $days): int { return 0; }
        };

        $policy = new RetentionPolicy('audit', $repository, 180);

        $this->assertSame('audit', $policy->appliesTo());
        $this->assertSame(180, $policy->retentionDays());
    }
}

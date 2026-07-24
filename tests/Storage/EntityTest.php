<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\JobStatus;
use AINewsAutomator\Storage\Entities\QueueJob;
use AINewsAutomator\Storage\Entities\SourceRecord;
use PHPUnit\Framework\TestCase;

final class EntityTest extends TestCase
{
    public function test_queue_job_round_trips_through_row(): void
    {
        $job = new QueueJob(
            id: 42,
            jobType: 'pipeline.fact_check',
            status: JobStatus::Pending,
            priority: 150,
            attempts: 1,
            maxAttempts: 5,
            worker: 'worker-1',
            payload: ['url' => 'https://example.test/article'],
            correlationId: 'corr-abc',
            runAfter: null,
            lockedAt: null,
            createdAt: EntityDates::fromMysql('2026-07-14 10:00:00'),
            startedAt: null,
        );

        $restored = QueueJob::fromRow(array_merge($job->toRow(), ['id' => 42]));

        $this->assertSame($job->jobType, $restored->jobType);
        $this->assertSame($job->status, $restored->status);
        $this->assertSame($job->priority, $restored->priority);
        $this->assertSame($job->payload, $restored->payload);
        $this->assertSame($job->correlationId, $restored->correlationId);
        $this->assertEquals($job->createdAt, $restored->createdAt);
    }

    public function test_queue_job_handles_null_optional_fields(): void
    {
        $row = [
            'id' => 1,
            'job_type' => 'x',
            'status' => 'pending',
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 5,
            'worker' => null,
            'payload' => '{}',
            'correlation_id' => null,
            'run_after' => null,
            'locked_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'started_at' => null,
        ];

        $job = QueueJob::fromRow($row);

        $this->assertNull($job->worker);
        $this->assertNull($job->correlationId);
        $this->assertNull($job->runAfter);
        $this->assertNull($job->startedAt);
    }

    public function test_source_record_round_trips_through_row(): void
    {
        $source = new SourceRecord(
            id: null,
            name: 'TechCrunch RSS',
            type: 'rss',
            config: ['url' => 'https://techcrunch.com/feed/'],
            enabled: true,
            lastFetchedAt: null,
            lastError: null,
            createdAt: EntityDates::now(),
            updatedAt: EntityDates::now(),
        );

        $row = $source->toRow();
        $restored = SourceRecord::fromRow(array_merge($row, ['id' => 1]));

        $this->assertSame('TechCrunch RSS', $restored->name);
        $this->assertSame('rss', $restored->type);
        $this->assertSame(['url' => 'https://techcrunch.com/feed/'], $restored->config);
        $this->assertTrue($restored->enabled);
    }

    public function test_entity_dates_mysql_round_trip(): void
    {
        $original = EntityDates::fromMysql('2026-07-14 15:30:00');
        $this->assertSame('2026-07-14 15:30:00', EntityDates::toMysql($original));
    }

    public function test_entity_dates_handles_zero_datetime_as_null(): void
    {
        $this->assertNull(EntityDates::nullableFromMysql('0000-00-00 00:00:00'));
        $this->assertNull(EntityDates::nullableFromMysql(null));
        $this->assertNull(EntityDates::nullableFromMysql(''));
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MigrationInterface;
use AINewsAutomator\Storage\Database\Connection;
use AINewsAutomator\Storage\Database\SchemaInspector;
use AINewsAutomator\Storage\Database\TransactionManager;
use AINewsAutomator\Storage\Migrations\MigrationRecorder;
use AINewsAutomator\Storage\Migrations\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real MigrationRecorder/MigrationRunner/SchemaInspector
 * against the FakeWpdb in-memory double, rather than mocking those
 * classes away — a closer approximation of real behavior than a pure
 * mock-based test, while still requiring no actual database.
 */
final class MigrationSystemTest extends TestCase
{
    private FakeWpdb $wpdb;
    private ConnectionInterface $connection;
    private SchemaInspector $inspector;
    private MigrationRecorder $recorder;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->connection = new Connection();
        $this->inspector = new SchemaInspector($this->connection);
        $this->recorder = new MigrationRecorder($this->connection, $this->inspector);
        $this->logger = new OptionBackedLogger(new CorrelationContext('test'), Environment::Development);
    }

    public function test_recorded_versions_is_empty_before_schema_migrations_table_exists(): void
    {
        // Bootstrap case: the tracking table itself doesn't exist yet.
        $this->assertSame([], $this->recorder->recordedVersions());
    }

    public function test_record_and_recorded_versions_round_trip(): void
    {
        $this->wpdb->createTable('wp_ana_schema_migrations');

        $this->recorder->record('20260101000001', 'First migration', 1);
        $this->recorder->record('20260101000002', 'Second migration', 1);

        $this->assertSame(['20260101000001', '20260101000002'], $this->recorder->recordedVersions());
    }

    public function test_next_batch_number_increments(): void
    {
        $this->wpdb->createTable('wp_ana_schema_migrations');

        $this->assertSame(1, $this->recorder->nextBatchNumber());

        $this->recorder->record('v1', 'desc', 1);
        $this->assertSame(2, $this->recorder->nextBatchNumber());
    }

    public function test_runner_applies_pending_migrations_in_version_order(): void
    {
        $this->wpdb->createTable('wp_ana_schema_migrations');

        $applied = [];
        $migrations = [
            $this->fakeMigration('20260101000003', function () use (&$applied): void { $applied[] = '3'; }),
            $this->fakeMigration('20260101000001', function () use (&$applied): void { $applied[] = '1'; }),
            $this->fakeMigration('20260101000002', function () use (&$applied): void { $applied[] = '2'; }),
        ];

        $runner = new MigrationRunner($this->connection, new TransactionManager(), $this->recorder, $this->logger);
        $result = $runner->migrate($migrations);

        $this->assertSame(['1', '2', '3'], $applied, 'Migrations must apply in ascending version order regardless of input order.');
        $this->assertSame(['20260101000001', '20260101000002', '20260101000003'], $result);
    }

    public function test_runner_skips_already_applied_migrations(): void
    {
        $this->wpdb->createTable('wp_ana_schema_migrations');
        $this->recorder->record('20260101000001', 'already done', 1);

        $ranAgain = false;
        $migrations = [
            $this->fakeMigration('20260101000001', function () use (&$ranAgain): void { $ranAgain = true; }),
        ];

        $runner = new MigrationRunner($this->connection, new TransactionManager(), $this->recorder, $this->logger);
        $result = $runner->migrate($migrations);

        $this->assertFalse($ranAgain);
        $this->assertSame([], $result);
    }

    public function test_has_pending_reflects_unrecorded_migrations(): void
    {
        $this->wpdb->createTable('wp_ana_schema_migrations');

        $migrations = [$this->fakeMigration('20260101000001', static function (): void {})];

        $runner = new MigrationRunner($this->connection, new TransactionManager(), $this->recorder, $this->logger);

        $this->assertTrue($runner->hasPending($migrations));

        $runner->migrate($migrations);

        $this->assertFalse($runner->hasPending($migrations));
    }

    public function test_schema_inspector_reports_missing_tables(): void
    {
        // No tables created in the fake at all.
        $missing = $this->inspector->missingTables();

        $this->assertNotEmpty($missing);
        $this->assertContains('queue', $missing);
    }

    public function test_schema_inspector_table_exists(): void
    {
        $this->wpdb->createTable('wp_ana_queue');

        $this->assertTrue($this->inspector->tableExists('queue'));
        $this->assertFalse($this->inspector->tableExists('sources'));
    }

    private function fakeMigration(string $version, callable $onUp): MigrationInterface
    {
        return new class ($version, $onUp) implements MigrationInterface {
            public function __construct(private readonly string $v, private readonly \Closure $callback)
            {
            }

            public function version(): string
            {
                return $this->v;
            }

            public function description(): string
            {
                return 'Fake migration ' . $this->v;
            }

            public function up(ConnectionInterface $connection): void
            {
                ($this->callback)();
            }

            public function down(ConnectionInterface $connection): void
            {
            }
        };
    }
}

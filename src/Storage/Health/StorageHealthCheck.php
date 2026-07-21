<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Health;

use AINewsAutomator\Security\Health\HealthCheckResult;
use AINewsAutomator\Security\Health\HealthStatus;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Database\SchemaInspector;
use AINewsAutomator\Storage\Database\Tables;
use AINewsAutomator\Storage\Migrations\MigrationManifest;
use AINewsAutomator\Storage\Migrations\MigrationRecorder;

/**
 * Runs Storage's diagnostic checks, reusing Security's HealthCheckResult
 * value object (a cross-module dependency on a public type Security
 * already exposes — not a modification of Security, just reuse of a
 * well-designed shape, consistent across every module's diagnostics page).
 */
final class StorageHealthCheck
{
    private const DOCS_BASE = 'https://example.com/ai-news-automator/docs/storage#';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly SchemaInspector $inspector,
        private readonly MigrationRecorder $recorder,
    ) {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkTablesExist(),
            $this->checkMigrationStatus(),
            $this->checkStorageEngine(),
            $this->checkIndexes(),
            $this->checkQueryPerformance(),
            $this->checkOrphanedImages(),
        ];
    }

    private function checkTablesExist(): HealthCheckResult
    {
        $missing = $this->inspector->missingTables();

        if ($missing === []) {
            return new HealthCheckResult(
                'Tables present',
                HealthStatus::Ok,
                'All ' . count(Tables::all()) . ' expected tables exist.'
            );
        }

        return new HealthCheckResult(
            'Tables present',
            HealthStatus::Critical,
            'Missing tables: ' . implode(', ', $missing),
            'Deactivate and reactivate the plugin to trigger migrations, or check the error log for a migration failure.',
            true,
            self::DOCS_BASE . 'missing-tables'
        );
    }

    private function checkMigrationStatus(): HealthCheckResult
    {
        $applied = $this->recorder->recordedVersions();
        $expected = MigrationManifest::migrations();

        $pending = array_filter($expected, static fn ($m): bool => !in_array($m->version(), $applied, true));

        if ($pending === []) {
            return new HealthCheckResult(
                'Migration status',
                HealthStatus::Ok,
                'Schema is up to date (' . count($applied) . ' migrations applied).'
            );
        }

        return new HealthCheckResult(
            'Migration status',
            HealthStatus::Warning,
            count($pending) . ' migration(s) pending.',
            'Migrations run automatically on the next request, or reactivate the plugin to trigger them immediately.',
            true,
            self::DOCS_BASE . 'migrations'
        );
    }

    private function checkStorageEngine(): HealthCheckResult
    {
        $engine = $this->inspector->tableEngine(Tables::QUEUE);

        if ($engine === null) {
            return new HealthCheckResult(
                'Storage engine',
                HealthStatus::Warning,
                'Could not determine the storage engine (table may not exist yet).'
            );
        }

        if (strtoupper($engine) !== 'INNODB') {
            return new HealthCheckResult(
                'Storage engine',
                HealthStatus::Critical,
                sprintf('Tables use the "%s" engine, not InnoDB.', $engine),
                'Transactions (used for queue completion and migrations) require InnoDB. Contact your host to convert the database default engine.',
                false,
                self::DOCS_BASE . 'engine'
            );
        }

        return new HealthCheckResult('Storage engine', HealthStatus::Ok, 'Tables use InnoDB; transactions are supported.');
    }

    private function checkIndexes(): HealthCheckResult
    {
        $missingIndexes = [];

        foreach ([Tables::QUEUE, Tables::AUDIT, Tables::LOGS] as $table) {
            if ($this->inspector->indexNames($table) === []) {
                $missingIndexes[] = $table;
            }
        }

        if ($missingIndexes === []) {
            return new HealthCheckResult('Index validation', HealthStatus::Ok, 'Key tables have indexes present.');
        }

        return new HealthCheckResult(
            'Index validation',
            HealthStatus::Warning,
            'No indexes detected on: ' . implode(', ', $missingIndexes),
            'This is expected if those tables do not exist yet; otherwise re-run migrations.',
            false,
            self::DOCS_BASE . 'indexes'
        );
    }

    private function checkQueryPerformance(): HealthCheckResult
    {
        if (!$this->inspector->tableExists(Tables::LOGS)) {
            return new HealthCheckResult('Query performance canary', HealthStatus::Ok, 'Skipped — logs table not yet created.');
        }

        $start = microtime(true);
        $this->connection->newQuery(Tables::LOGS)->limit(1)->get();
        $durationMs = (microtime(true) - $start) * 1000;

        if ($durationMs > 500) {
            return new HealthCheckResult(
                'Query performance canary',
                HealthStatus::Warning,
                sprintf('A simple indexed query took %.1fms, slower than expected.', $durationMs),
                'This may indicate database load or a hosting-level performance issue, not necessarily a Storage bug.',
                false,
                self::DOCS_BASE . 'performance'
            );
        }

        return new HealthCheckResult('Query performance canary', HealthStatus::Ok, sprintf('Simple query completed in %.1fms.', $durationMs));
    }

    private function checkOrphanedImages(): HealthCheckResult
    {
        if (!$this->inspector->tableExists(Tables::IMAGES)) {
            return new HealthCheckResult('Orphan detection', HealthStatus::Ok, 'Skipped — images table not yet created.');
        }

        global $wpdb;

        // Cheap presence check only — the health page doesn't need the full
        // orphan row set, just a signal. ImageRepository::findOrphans()
        // does the actual work when a cleanup action is invoked.
        $imagesTable = $this->connection->table(Tables::IMAGES);
        $postsTable = $wpdb->posts;

        $count = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM `{$imagesTable}` img LEFT JOIN `{$postsTable}` p ON p.ID = img.article_id WHERE img.article_id IS NOT NULL AND p.ID IS NULL"
        );

        if ($count === 0) {
            return new HealthCheckResult('Orphan detection', HealthStatus::Ok, 'No orphaned image records found.');
        }

        return new HealthCheckResult(
            'Orphan detection',
            HealthStatus::Warning,
            sprintf('%d image record(s) reference a deleted article.', $count),
            'These are harmless but can be cleaned up via the Images repository\'s orphan-purge action.',
            true,
            self::DOCS_BASE . 'orphans'
        );
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MigrationInterface;
use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Exceptions\MigrationException;

/**
 * Applies pending migrations (from MigrationManifest) in ascending
 * version order, recording each in MigrationRecorder as it succeeds.
 *
 * Honest caveat on transactions here: every migration's up() is wrapped
 * in TransactionManager::transactional() for data migrations' genuine
 * benefit, but MySQL DDL statements (CREATE TABLE, ALTER TABLE) cause an
 * implicit commit regardless of an open transaction — so for
 * schema-creation migrations (the majority in this initial batch) the
 * wrap is harmless but does not provide real atomicity across multiple
 * DDL statements. This is stated plainly rather than implied otherwise.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TransactionManagerInterface $transactions,
        private readonly MigrationRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<MigrationInterface> $migrations The full manifest, any order.
     * @return list<string> Versions that were applied by this call.
     */
    public function migrate(array $migrations): array
    {
        $applied = $this->recorder->recordedVersions();
        $batch = $this->recorder->nextBatchNumber();

        $pending = array_values(array_filter(
            $migrations,
            static fn (MigrationInterface $m): bool => !in_array($m->version(), $applied, true)
        ));

        usort($pending, static fn (MigrationInterface $a, MigrationInterface $b): int => $a->version() <=> $b->version());

        $appliedNow = [];

        foreach ($pending as $migration) {
            $this->applyOne($migration, $batch);
            $appliedNow[] = $migration->version();
        }

        return $appliedNow;
    }

    private function applyOne(MigrationInterface $migration, int $batch): void
    {
        $this->logger->info('Applying migration {version}: {description}', [
            'version'     => $migration->version(),
            'description' => $migration->description(),
        ]);

        try {
            $this->transactions->transactional(function () use ($migration): void {
                $migration->up($this->connection);
            });
        } catch (\Throwable $e) {
            $this->logger->critical('Migration {version} failed: {error}', [
                'version' => $migration->version(),
                'error'   => $e->getMessage(),
            ]);

            throw MigrationException::forVersion($migration->version(), $e->getMessage());
        }

        // Record only after up() succeeds — the migration's own table
        // (ana_schema_migrations) is created by the first migration, so by
        // the time we reach this line for that migration, the table exists.
        $this->recorder->record($migration->version(), $migration->description(), $batch);

        $this->logger->info('Migration {version} applied successfully.', ['version' => $migration->version()]);
    }

    /**
     * Cheap "is anything pending" check for the automatic-upgrade-detection
     * path on plugins_loaded, without actually running anything.
     *
     * @param list<MigrationInterface> $migrations
     */
    public function hasPending(array $migrations): bool
    {
        $applied = $this->recorder->recordedVersions();

        foreach ($migrations as $migration) {
            if (!in_array($migration->version(), $applied, true)) {
                return true;
            }
        }

        return false;
    }
}

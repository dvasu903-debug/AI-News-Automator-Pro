<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * A single versioned schema/data change. version() must be unique and
 * sortable (timestamp-based, e.g. "20260714000001") — MigrationRunner
 * applies pending migrations in ascending version order.
 */
interface MigrationInterface
{
    public function version(): string;

    public function description(): string;

    public function up(ConnectionInterface $connection): void;

    /**
     * Best-effort reversal. For schema-creation migrations (via dbDelta)
     * this is commonly a documented no-op — see AbstractMigration's
     * default and the module README's honest scoping of rollback support.
     */
    public function down(ConnectionInterface $connection): void;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations;

use AINewsAutomator\Storage\Contracts\ConnectionInterface;
use AINewsAutomator\Storage\Contracts\MigrationInterface;

/**
 * Base class for migrations. Table-creation migrations extend this and
 * only implement up() via dbDelta() (see CreatesTableViaDbDelta trait) —
 * down() defaults to a logged no-op, because dbDelta has no native
 * reversal and a hand-rolled DROP TABLE is a correctness risk this
 * module does not paper over. Data-only migrations should override
 * down() with the genuine inverse operation.
 */
abstract class AbstractMigration implements MigrationInterface
{
    /**
     * Default down(): logs that this migration does not support rollback,
     * rather than silently doing nothing. Data migrations override this.
     */
    public function down(ConnectionInterface $connection): void
    {
        // Intentionally a documented no-op by default. See class docblock.
    }
}

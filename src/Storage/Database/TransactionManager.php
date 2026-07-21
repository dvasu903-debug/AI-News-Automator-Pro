<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Database;

use AINewsAutomator\Storage\Contracts\TransactionManagerInterface;
use AINewsAutomator\Storage\Exceptions\TransactionException;

/**
 * Wraps $wpdb transaction control. Requires InnoDB (or another
 * transactional engine) — on MyISAM these statements are silently
 * accepted but do nothing, which is why StorageHealthCheck verifies the
 * table engine explicitly rather than relying on this class to detect it.
 *
 * Nested transactional() calls use SAVEPOINTs: the outermost call issues
 * START TRANSACTION/COMMIT/ROLLBACK; inner calls issue SAVEPOINT/RELEASE
 * SAVEPOINT/ROLLBACK TO SAVEPOINT, so an inner failure can be rolled back
 * without prematurely committing or aborting the outer transaction.
 */
final class TransactionManager implements TransactionManagerInterface
{
    private int $depth = 0;

    public function begin(): void
    {
        global $wpdb;

        if ($this->depth === 0) {
            $wpdb->query('START TRANSACTION');
        } else {
            $wpdb->query('SAVEPOINT ana_sp_' . $this->depth);
        }

        $this->depth++;
    }

    public function commit(): void
    {
        global $wpdb;

        if ($this->depth === 0) {
            throw new TransactionException('Cannot commit: no transaction is active.');
        }

        $this->depth--;

        if ($this->depth === 0) {
            $wpdb->query('COMMIT');
        } else {
            $wpdb->query('RELEASE SAVEPOINT ana_sp_' . $this->depth);
        }
    }

    public function rollback(): void
    {
        global $wpdb;

        if ($this->depth === 0) {
            throw new TransactionException('Cannot roll back: no transaction is active.');
        }

        $this->depth--;

        if ($this->depth === 0) {
            $wpdb->query('ROLLBACK');
        } else {
            $wpdb->query('ROLLBACK TO SAVEPOINT ana_sp_' . $this->depth);
        }
    }

    public function transactional(callable $work): mixed
    {
        $this->begin();

        try {
            $result = $work();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}

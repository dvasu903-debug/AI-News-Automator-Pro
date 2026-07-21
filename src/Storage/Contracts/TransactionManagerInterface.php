<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Transaction control. The primary API is transactional() — begin/commit/
 * rollback are exposed for advanced cases but most callers should never
 * need them directly. Nested transactional() calls use SAVEPOINTs so an
 * inner call composes safely instead of prematurely committing the outer
 * transaction.
 */
interface TransactionManagerInterface
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    /**
     * Runs $work inside a transaction (or a savepoint, if already inside
     * one). Commits on normal return, rolls back and rethrows on any
     * exception.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    public function inTransaction(): bool;
}

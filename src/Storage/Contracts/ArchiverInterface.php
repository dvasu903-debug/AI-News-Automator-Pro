<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Contracts;

/**
 * Extension point for moving old rows to cold storage instead of deleting
 * them outright (an alternative/complement to RetentionPolicyInterface's
 * purge). Not implemented in Module 3 — no default binding is registered.
 * A future module can implement this (e.g. archive to a flat file, a
 * remote object store, or a separate "cold" table) and bind it without
 * any repository changing, since repositories that support archiving
 * depend on this interface, not a concrete archiver.
 */
interface ArchiverInterface
{
    /**
     * Archives rows older than $before for the given logical table.
     * Returns the number of rows archived. Implementations decide their
     * own destination and format.
     */
    public function archive(string $logicalTable, \DateTimeImmutable $before): int;
}

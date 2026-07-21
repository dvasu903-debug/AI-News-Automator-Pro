<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

use AINewsAutomator\Security\Audit\AuditEntry;

/**
 * Storage seam for audit entries. The option-backed default is replaced
 * by a table-backed implementation in Module 3 (Storage) via a container
 * rebinding — AuditLogger depends on this interface, never on a concrete
 * store, so nothing else changes.
 */
interface AuditLogRepositoryInterface
{
    public function persist(AuditEntry $entry): void;

    /**
     * @return list<AuditEntry> Most recent first.
     */
    public function recent(int $limit): array;

    /**
     * Deletes all stored audit entries (uninstall).
     */
    public function purge(): void;
}

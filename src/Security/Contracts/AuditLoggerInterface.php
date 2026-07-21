<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

use AINewsAutomator\Security\Audit\AuditEntry;

/**
 * Records security-relevant actions. Emits a SecurityEvent per entry so
 * ThreatDetector and (later) Monitoring can react. Storage is delegated
 * to an AuditLogRepositoryInterface, so moving from options to a table
 * in Module 3 never changes callers.
 */
interface AuditLoggerInterface
{
    public function record(AuditEntry $entry): void;

    /**
     * @return list<AuditEntry> Most recent first.
     */
    public function recent(int $limit = 50): array;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Audit;

/**
 * Severity of an audited security event. Ordered so consumers can filter
 * "warning and above". Reuses the conceptual scale of PSR-3 but scoped to
 * the security domain's needs.
 */
enum AuditSeverity: string
{
    case Info     = 'info';
    case Warning  = 'warning';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Info     => 0,
            self::Warning  => 1,
            self::Critical => 2,
        };
    }
}

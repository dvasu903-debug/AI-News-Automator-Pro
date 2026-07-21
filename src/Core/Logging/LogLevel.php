<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Logging;

/**
 * The eight PSR-3 / RFC 5424 severity levels, as a type-safe enum with
 * an ordering so consumers can filter "error and above". Replaces the
 * loose validated-string level of the Module 1.0 logger.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert     = 'alert';
    case Critical  = 'critical';
    case Error     = 'error';
    case Warning   = 'warning';
    case Notice    = 'notice';
    case Info      = 'info';
    case Debug     = 'debug';

    /**
     * Numeric severity — lower is more severe, matching RFC 5424. Used to
     * compare levels (e.g. "is this at least as severe as Error?").
     */
    public function severity(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert     => 1,
            self::Critical  => 2,
            self::Error     => 3,
            self::Warning   => 4,
            self::Notice    => 5,
            self::Info      => 6,
            self::Debug     => 7,
        };
    }

    public function isAtLeastAsSevereAs(self $other): bool
    {
        return $this->severity() <= $other->severity();
    }
}

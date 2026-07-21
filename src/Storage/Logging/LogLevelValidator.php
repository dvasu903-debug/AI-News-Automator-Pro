<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Logging;

use AINewsAutomator\Storage\Exceptions\ValidationException;

/**
 * The eight PSR-3 levels, as the single source of truth shared by
 * LogRepository's validate() and TableBackedLogger — avoiding the level
 * list being duplicated (and potentially drifting) across both.
 */
final class LogLevelValidator
{
    public const VALID_LEVELS = [
        'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug',
    ];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::VALID_LEVELS, true);
    }

    /**
     * @throws ValidationException
     */
    public static function assertValid(string $level): void
    {
        if (!self::isValid($level)) {
            throw new ValidationException(
                ['level' => 'Unrecognized log level: ' . $level],
                'Invalid log level.'
            );
        }
    }
}

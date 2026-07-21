<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Config;

/**
 * The runtime environment the plugin is executing in. Drives behavior
 * that should differ between a developer's local site and a live
 * production site — for example, whether debug-level logs are persisted,
 * or whether the container performs its (slightly costly) circular-
 * dependency detection on every resolution.
 */
enum Environment: string
{
    case Production  = 'production';
    case Staging     = 'staging';
    case Development = 'development';

    /**
     * Detects the environment, preferring WordPress's own
     * wp_get_environment_type() (available since WP 5.5) and falling
     * back to production — the safe default, since it's the most
     * conservative (least verbose logging, most caching).
     */
    public static function detect(): self
    {
        $type = function_exists('wp_get_environment_type')
            ? wp_get_environment_type()
            : 'production';

        return match ($type) {
            'development', 'local' => self::Development,
            'staging'              => self::Staging,
            default                => self::Production,
        };
    }

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    public function isDevelopment(): bool
    {
        return $this === self::Development;
    }
}

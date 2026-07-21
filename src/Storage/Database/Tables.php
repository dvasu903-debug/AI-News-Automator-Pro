<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Database;

/**
 * The single source of truth for the module's logical table names.
 * Physical names are `{$wpdb->prefix}ana_{logical}` (e.g. `wp_ana_queue`),
 * resolved by Connection::table(). Centralizing the list here means a
 * table name is never a repeated string literal scattered across
 * repositories and migrations.
 */
final class Tables
{
    public const SCHEMA_MIGRATIONS = 'schema_migrations';
    public const QUEUE             = 'queue';
    public const JOBS              = 'jobs';
    public const LOGS              = 'logs';
    public const AUDIT             = 'audit';
    public const METRICS           = 'metrics';
    public const METRIC_COUNTERS   = 'metric_counters';
    public const SOURCES           = 'sources';
    public const WORKFLOWS         = 'workflows';
    public const AI_REQUESTS       = 'ai_requests';
    public const IMAGES            = 'images';

    /**
     * @return list<string> Every logical table this module owns, in an order
     *                      safe for sequential creation (no forward references).
     */
    public static function all(): array
    {
        return [
            self::SCHEMA_MIGRATIONS,
            self::QUEUE,
            self::JOBS,
            self::LOGS,
            self::AUDIT,
            self::METRICS,
            self::METRIC_COUNTERS,
            self::SOURCES,
            self::WORKFLOWS,
            self::AI_REQUESTS,
            self::IMAGES,
        ];
    }
}

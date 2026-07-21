<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Migrations;

/**
 * Shared helper for table-creation migrations. Centralizes the
 * require-upgrade.php + dbDelta() boilerplate so no individual migration
 * duplicates it. dbDelta is the WordPress-idiomatic way to create/alter
 * custom tables: it's idempotent and correctly diffs column definitions
 * across MySQL/MariaDB variants, which a hand-rolled `CREATE TABLE IF NOT
 * EXISTS` does not do on upgrade.
 *
 * dbDelta has strict formatting requirements: each column definition on
 * its own line, two spaces after PRIMARY KEY, KEY/INDEX lines exactly as
 * shown. Deviating from this formatting causes dbDelta to silently skip
 * the intended change, so every migration using this helper follows the
 * same formatting convention.
 */
final class SchemaBuilder
{
    /**
     * Runs one or more CREATE TABLE statements through dbDelta.
     *
     * @param list<string> $createStatements
     */
    public static function run(array $createStatements): void
    {
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta($createStatements);
    }

    public static function charsetCollate(): string
    {
        global $wpdb;
        return $wpdb->get_charset_collate();
    }

    public static function tableName(string $logicalName): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ana_' . $logicalName;
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Implemented by any module that needs to run setup or teardown logic
 * tied to the plugin's activation/deactivation/uninstall lifecycle —
 * for example, the Storage module creating custom database tables for
 * the Queue module, or the Scheduler module registering custom cron
 * intervals.
 *
 * Providers that need this simply implement it in addition to
 * ServiceProviderInterface; Activator/Deactivator/Uninstaller discover
 * and call it automatically. Modules that don't need lifecycle hooks
 * (most of them) simply don't implement this interface — no empty
 * boilerplate methods required.
 */
interface ActivatableInterface
{
    /**
     * Runs once, when the plugin is activated. Must be idempotent —
     * WordPress may call this multiple times across upgrades/reactivations,
     * so implementations should use `CREATE TABLE IF NOT EXISTS`, check
     * before creating options, etc.
     */
    public function activate(): void;

    /**
     * Runs when the plugin is deactivated. Should pause background work
     * (unschedule cron events, etc.) without destroying data — deactivation
     * is reversible, uninstall is not.
     */
    public function deactivate(): void;

    /**
     * Runs only when the plugin is fully deleted via wp-admin, never on
     * a plain deactivation. Should remove everything this module created:
     * tables, options, transients, scheduled events.
     */
    public function uninstall(): void;
}

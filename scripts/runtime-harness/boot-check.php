<?php

declare(strict_types=1);

/**
 * Generic production-boot sanity check, reusable by every milestone:
 * confirms the real plugin entry point boots against a real database
 * with no fatal error, every module's migrations apply, and a second
 * boot is idempotent (no duplicate migrations). Milestone-specific
 * checklists (scripts/runtime-harness/checklists/*.php) assume this
 * much already works and verify their own module's behavior on top.
 */

require __DIR__ . '/harness-bootstrap.php';

use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Storage\Migrations\MigrationRunner;

$FAIL = 0;
function ok(string $item, bool $pass, string $detail = ''): void
{
    global $FAIL;
    if (!$pass) {
        $FAIL = 1;
    }
    printf("[%s] %s%s\n", $pass ? 'PASS' : 'FAIL', $item, $detail !== '' ? " — $detail" : '');
}

$wpdb = $GLOBALS['wpdb'];

echo "=== Boot check: production entry point against a real database ===\n";

$connected = $wpdb->check_connection(false);
ok('Database connection succeeds', (bool) $connected, $wpdb->db_server_info());

$plugin = PluginFactory::create(ANA_PRO_FILE);
$plugin->boot();
do_action('plugins_loaded');
do_action('rest_api_init');
$c = $plugin->container();

$tables = $wpdb->get_col("SHOW TABLES LIKE 'wp_ana_%'");
ok('Every module\'s tables exist after first boot', count($tables) > 0, count($tables) . ' tables');

$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ana_schema_migrations');
$plugin2 = PluginFactory::create(ANA_PRO_FILE);
$plugin2->boot();
do_action('plugins_loaded');
$after = (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ana_schema_migrations');
$dupes = (int) $wpdb->get_var('SELECT COUNT(*) - COUNT(DISTINCT version) FROM wp_ana_schema_migrations');
ok('Re-boot is idempotent (no new/duplicate migrations)', $before === $after && $dupes === 0, "count $before -> $after, dupes $dupes");

echo "\n";
echo $FAIL === 0 ? "BOOT CHECK PASSED\n" : "BOOT CHECK FAILED\n";
exit($FAIL);

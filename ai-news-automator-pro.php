<?php

/**
 * Plugin Name: AI News Automator Pro
 * Description: Enterprise-grade AI publishing pipeline for WordPress — source discovery, research, fact verification, AI writing, SEO, image processing, publishing, social sharing, and analytics.
 * Version: 2.0.0-dev
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * Author: Your Site
 * Text Domain: ai-news-automator
 *
 * @package AINewsAutomator
 */

declare(strict_types=1);

if (!defined('WPINC')) {
    exit;
}

// These two file-scope constants are the ONLY globals the plugin defines.
// They exist because the WordPress plugin header, the activation-hook
// registration, and the version stamp all need file-scope values before
// any autoloading has happened. They are read in exactly one place —
// PluginConfig::fromPluginFile() — and never referenced elsewhere; every
// other class receives configuration through the injected PluginConfig.
define('ANA_PRO_VERSION', '2.0.0-dev');
define('ANA_PRO_FILE', __FILE__);

$ana_pro_autoloader = __DIR__ . '/vendor/autoload.php';

if (!file_exists($ana_pro_autoloader)) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'AI News Automator Pro: build incomplete — the bundled autoloader is missing. Reinstall the release ZIP from the releases page.',
                'ai-news-automator'
            )
        );
    });

    return;
}

require_once $ana_pro_autoloader;

use AINewsAutomator\Core\Activator;
use AINewsAutomator\Core\Deactivator;
use AINewsAutomator\Core\PluginFactory;

/**
 * Composition root.
 *
 * Each WordPress entry point (activation, deactivation, normal boot)
 * builds its own kernel via PluginFactory::create() inside its own
 * closure. There is no shared/cached kernel instance and no global
 * accessor — only one of these closures ever fires in a given request,
 * so building per-entry-point costs nothing and keeps all state local.
 * uninstall.php is the fourth entry point and lives in its own file
 * because WordPress loads it independently of this bootstrap.
 */

register_activation_hook(ANA_PRO_FILE, static function (): void {
    (new Activator(PluginFactory::create(ANA_PRO_FILE)))->activate();
});

register_deactivation_hook(ANA_PRO_FILE, static function (): void {
    (new Deactivator(PluginFactory::create(ANA_PRO_FILE)))->deactivate();
});

add_action('plugins_loaded', static function (): void {
    PluginFactory::create(ANA_PRO_FILE)->boot();
});

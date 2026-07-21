<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$ana_pro_autoloader = __DIR__ . '/vendor/autoload.php';

if (!file_exists($ana_pro_autoloader)) {
    return;
}

require_once $ana_pro_autoloader;

// The main plugin file isn't loaded during uninstall, so the constants it
// defines don't exist here — define the file path the factory needs.
if (!defined('ANA_PRO_VERSION')) {
    define('ANA_PRO_VERSION', '2.0.0-dev');
}

$ana_pro_plugin_file = __DIR__ . '/ai-news-automator-pro.php';

(new \AINewsAutomator\Core\Uninstaller(
    \AINewsAutomator\Core\PluginFactory::create($ana_pro_plugin_file)
))->uninstall();

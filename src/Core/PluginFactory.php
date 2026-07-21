<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\PluginConfig;

/**
 * The single composition root for the plugin.
 *
 * Replaces the former Plugin::instance() singleton. Instead of a
 * globally-cached instance reachable from anywhere, construction is an
 * explicit, side-effect-free function that each WordPress entry point
 * (normal boot, activation, deactivation, uninstall) calls when it needs
 * a kernel. Because building a kernel is cheap (it only assembles objects;
 * boot() is what does the work) there is no need to cache it globally, and
 * not caching it is precisely what removes the shared mutable state.
 *
 * "Isn't constructing the kernel twice per request wasteful?" — No: only
 * ONE entry point fires per request. A normal page load calls create()
 * once via plugins_loaded; an activation request calls it once via the
 * activation hook. They are never both live in the same request.
 */
final class PluginFactory
{
    /**
     * Builds a fully-assembled (but not yet booted) kernel: a fresh
     * container, the plugin configuration object registered as a shared
     * instance, and the active module manifest applied.
     *
     * @param non-empty-string $pluginFile Absolute path to the main plugin file.
     */
    public static function create(string $pluginFile): Plugin
    {
        $container = new Container();

        $config = PluginConfig::fromPluginFile(
            $pluginFile,
            Environment::detect()
        );

        // Registered before boot so every provider's register()/boot() and
        // every autowired class can depend on PluginConfig immediately.
        $container->instance(PluginConfig::class, $config);

        return new Plugin($container, ModuleManifest::providers());
    }
}

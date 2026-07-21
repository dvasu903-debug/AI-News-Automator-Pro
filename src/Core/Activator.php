<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Config\PluginConfig;
use AINewsAutomator\Core\Contracts\ActivatableInterface;

/**
 * Handles the plugin activation lifecycle event.
 *
 * Receives an already-constructed Plugin (built by PluginFactory in the
 * bootstrap file) rather than reaching for a global singleton. Boots it
 * so every provider's bindings exist, then calls activate() on every
 * provider implementing ActivatableInterface.
 */
final class Activator
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function activate(): void
    {
        $this->plugin->boot();

        foreach ($this->plugin->providers() as $provider) {
            if ($provider instanceof ActivatableInterface) {
                $provider->activate();
            }
        }

        $config = $this->plugin->container()->get(PluginConfig::class);
        update_option('ai_news_automator_version', $config->version());
        update_option('ai_news_automator_activated_at', current_time('mysql'));

        flush_rewrite_rules();
    }
}

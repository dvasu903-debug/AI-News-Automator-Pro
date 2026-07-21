<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Contracts\ActivatableInterface;

/**
 * Handles the plugin uninstall lifecycle event — invoked only from the
 * top-level uninstall.php when the plugin is fully deleted. The only
 * lifecycle stage that destroys data. Receives an already-constructed
 * Plugin rather than a global singleton.
 */
final class Uninstaller
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function uninstall(): void
    {
        $this->plugin->boot();

        foreach ($this->plugin->providers() as $provider) {
            if ($provider instanceof ActivatableInterface) {
                $provider->uninstall();
            }
        }

        delete_option('ai_news_automator_version');
        delete_option('ai_news_automator_activated_at');
    }
}

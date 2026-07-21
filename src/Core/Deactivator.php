<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Contracts\ActivatableInterface;

/**
 * Handles the plugin deactivation lifecycle event. Deactivation is
 * reversible — pauses background work without destroying data. Receives
 * an already-constructed Plugin rather than a global singleton.
 */
final class Deactivator
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function deactivate(): void
    {
        $this->plugin->boot();

        foreach ($this->plugin->providers() as $provider) {
            if ($provider instanceof ActivatableInterface) {
                $provider->deactivate();
            }
        }

        flush_rewrite_rules();
    }
}

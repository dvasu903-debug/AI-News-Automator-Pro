<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\ServiceProviderInterface;

/**
 * Base class for module service providers. A module can extend this
 * and only override the phase it actually needs — most providers only
 * need register(), and boot() can stay a no-op.
 */
abstract class AbstractServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Intentionally empty — override when the module has bindings to register.
    }

    public function boot(ContainerInterface $container): void
    {
        // Intentionally empty — override when the module needs to wire WordPress hooks.
    }
}

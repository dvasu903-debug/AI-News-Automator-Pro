<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\ServiceProviderInterface;

/**
 * The plugin kernel. Drives the two-phase provider lifecycle (register
 * all providers, then boot all providers) over an injected container.
 *
 * This class holds NO static state and exposes NO static accessor. It
 * receives its Container through the constructor and is itself
 * constructed exactly once, in the WordPress bootstrap file (the single
 * permitted composition root). Business logic never reaches the kernel
 * or the container statically — everything downstream receives its
 * dependencies through its own constructor, resolved by the container's
 * autowiring. This is the change that makes the whole plugin unit-testable
 * without global teardown between tests: there is simply no global to tear
 * down.
 */
final class Plugin
{
    /** @var list<ServiceProviderInterface> */
    private array $providers = [];

    private bool $booted = false;

    /**
     * @param ContainerInterface $container The composition root's container.
     * @param list<class-string<ServiceProviderInterface>> $providerClasses
     *        The active module manifest (from ModuleManifest::providers()).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private array $providerClasses = [],
    ) {
        // The container must be able to resolve itself and its interface,
        // so a class can type-hint ContainerInterface in the rare, legitimate
        // case it needs to resolve tagged collections at runtime (e.g. an
        // aggregator that fans out over every registered connector).
        $this->container->instance(ContainerInterface::class, $this->container);

        if ($this->container instanceof Container) {
            $this->container->instance(Container::class, $this->container);
        }
    }

    /**
     * Appends a provider class to the manifest. Optional — the manifest
     * is normally passed whole into the constructor — but kept for
     * ergonomic incremental assembly and for tests that build a kernel
     * with a single provider.
     *
     * @param class-string<ServiceProviderInterface> $providerClass
     */
    public function withProvider(string $providerClass): self
    {
        if ($this->booted) {
            throw new \LogicException(
                'Cannot register a new provider after the plugin has already booted.'
            );
        }

        $this->providerClasses[] = $providerClass;

        return $this;
    }

    /**
     * Runs the two-phase provider lifecycle. Every provider's register()
     * is called first (bindings only, no WordPress hooks), so that once
     * any provider's boot() runs, every module's bindings already exist
     * in the container regardless of manifest order.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providerClasses as $providerClass) {
            $provider = new $providerClass();
            $this->providers[] = $provider;
            $provider->register($this->container);
        }

        foreach ($this->providers as $provider) {
            $provider->boot($this->container);
        }

        $this->booted = true;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @return list<ServiceProviderInterface> All providers, after boot() has run.
     */
    public function providers(): array
    {
        return $this->providers;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}

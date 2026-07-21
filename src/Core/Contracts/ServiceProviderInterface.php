<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Contract for a module's service provider.
 *
 * Every module (AI, Sources, Research, Pipeline, Queue, Scheduler, SEO,
 * Images, Publishing, Social, Analytics, Dashboard, Monitoring, Security,
 * Storage) exposes exactly one class implementing this interface. The
 * Plugin kernel discovers, registers, and boots providers in two distinct
 * phases so that bindings from every module are available in the
 * container before any module's WordPress hooks fire — this avoids the
 * "did module A load before module B needed it" ordering bugs that
 * plague single-file WordPress plugins.
 */
interface ServiceProviderInterface
{
    /**
     * Phase 1: bind services into the container.
     *
     * MUST NOT call any WordPress hook functions (add_action/add_filter)
     * here, and MUST NOT resolve other providers' services yet — other
     * providers may not have registered their bindings at this point.
     * This phase is purely "declare what this module provides."
     */
    public function register(ContainerInterface $container): void;

    /**
     * Phase 2: wire up WordPress hooks and resolve dependencies.
     *
     * Called only after every provider's register() has run, so it is
     * safe here to resolve bindings that other modules provided.
     */
    public function boot(ContainerInterface $container): void;
}

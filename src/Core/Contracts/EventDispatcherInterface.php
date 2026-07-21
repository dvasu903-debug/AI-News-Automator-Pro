<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * PSR-14-shaped event dispatcher contract (defined locally for the same
 * standalone-without-composer-vendor reason as ContainerInterface).
 *
 * This is the plugin-internal event system — distinct from WordPress's
 * own add_action/do_action, which remains how the plugin integrates
 * with WordPress core and other plugins. Internal cross-module
 * communication (e.g. Pipeline announcing "a draft was created" so
 * SEO, Images, and Analytics can each react without Pipeline knowing
 * any of them exist) goes through this dispatcher instead, because it
 * gives typed event objects and testable listener registration that
 * plain WordPress hooks don't.
 */
interface EventDispatcherInterface
{
    /**
     * Dispatches an event to every registered listener for its class
     * (and any parent classes/interfaces it implements), in descending
     * priority order. Returns the same event instance, so listeners
     * that mutate the event (or call stopPropagation() on a
     * StoppableEventInterface event) are visible to the caller.
     */
    public function dispatch(object $event): object;

    /**
     * @param class-string $eventClass
     * @param callable(object): void $listener
     * @param int $priority Higher runs first. Default matches WordPress's add_action default of 10.
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 10): void;
}

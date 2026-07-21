<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Minimal PSR-11-shaped container contract.
 *
 * We define this locally rather than depending on psr/container so the
 * plugin runs standalone without requiring `composer install` on every
 * target site. If you vendor the real psr/container package later,
 * Container can implement that interface as well without breaking
 * anything that type-hints against this one.
 */
interface ContainerInterface
{
    /**
     * Resolve an entry from the container by its identifier.
     *
     * @param string $id Identifier of the entry to look up (typically a FQCN or interface name).
     *
     * @throws \AINewsAutomator\Core\Exceptions\NotFoundException  If no entry was found for this identifier.
     * @throws \AINewsAutomator\Core\Exceptions\ContainerException If the entry could not be resolved.
     */
    public function get(string $id): mixed;

    /**
     * Determine whether the container can resolve the given identifier.
     */
    public function has(string $id): bool;

    /**
     * Bind a concrete implementation, closure, or instance to an identifier.
     * Re-resolved on every get() call.
     */
    public function bind(string $id, \Closure|string $concrete): void;

    /**
     * Bind a resolver that is only ever invoked once; subsequent get() calls
     * return the same cached instance.
     */
    public function singleton(string $id, \Closure|string $concrete): void;

    /**
     * Register an already-constructed instance under the given identifier.
     */
    public function instance(string $id, mixed $instance): void;

    /**
     * Register an alias so that resolving $alias resolves $target. Used
     * for ergonomic short names and for pointing an interface at whichever
     * concrete a module selected (e.g. alias AIProviderInterface to the
     * configured provider class).
     */
    public function alias(string $alias, string $target): void;

    /**
     * Associate an identifier with a tag. Multiple identifiers can share
     * a tag; tagged($tag) returns all of them resolved. This is what lets
     * an aggregator depend on "every source connector" without naming each
     * one — the mechanism flagged in the architecture plan as required for
     * the Sources module.
     */
    public function tag(string $id, string $tag): void;

    /**
     * Resolve every identifier registered under the given tag.
     *
     * @return list<mixed>
     */
    public function tagged(string $tag): array;

    /**
     * Register a lazily-resolved service. The concrete is not built until
     * the returned lazy proxy is first used. get() on a lazy binding returns
     * the proxy immediately; construction is deferred to first method call.
     */
    public function lazy(string $id, \Closure $concrete): void;
}

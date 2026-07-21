<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

/**
 * A minimal lazy proxy. Wraps a factory closure and only invokes it the
 * first time a method is called on the proxy, caching the resulting real
 * object thereafter.
 *
 * Deliberately simple: it forwards method calls via __call and property
 * access via __get/__set. It is NOT a transparent type-compatible proxy
 * (it does not `implements` the target's interface), so it suits the
 * case where a service is expensive to build and only conditionally used
 * — e.g. an HTTP client that most requests never touch — invoked through
 * a small, known method surface. For cases needing true type-transparent
 * lazy proxies, a library like symfony/var-exporter's LazyGhost is the
 * documented upgrade path; this class covers the plugin's actual needs
 * without pulling in that dependency.
 *
 * @template T of object
 */
final class LazyProxy
{
    /** @var (\Closure(): T)|null */
    private ?\Closure $factory;

    /** @var T|null */
    private ?object $resolved = null;

    /**
     * @param \Closure(): T $factory
     */
    public function __construct(\Closure $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @return T
     */
    private function instance(): object
    {
        if ($this->resolved === null) {
            $factory = $this->factory;
            /** @var T $object */
            $object = $factory();
            $this->resolved = $object;
            $this->factory = null;
        }

        return $this->resolved;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->instance()->{$method}(...$arguments);
    }

    public function __get(string $name): mixed
    {
        return $this->instance()->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->instance()->{$name} = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->instance()->{$name});
    }

    /**
     * Forces resolution and returns the underlying object — for callers
     * that need the real instance rather than proxied access.
     *
     * @return T
     */
    public function unwrap(): object
    {
        return $this->instance();
    }
}

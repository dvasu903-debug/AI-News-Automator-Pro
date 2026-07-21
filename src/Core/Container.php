<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Exceptions\CircularDependencyException;
use AINewsAutomator\Core\Exceptions\ContainerException;
use AINewsAutomator\Core\Exceptions\NotFoundException;

/**
 * The dependency injection container.
 *
 * Binding lifecycles:
 *  - bind():      resolved fresh on every get().
 *  - singleton(): resolved once, cached for the request.
 *  - instance():  a pre-built object registered directly.
 *  - lazy():      returns a LazyProxy; the real object is built on first use.
 *
 * Plus:
 *  - alias():   resolve one id by delegating to another.
 *  - tag()/tagged(): resolve a whole collection registered under a tag.
 *
 * Autowiring: an unbound class-string is constructed by reflecting its
 * constructor and recursively resolving typed class/interface parameters,
 * with circular-dependency detection so a cycle throws a clear exception
 * instead of exhausting memory.
 *
 * Backward compatibility: every method that existed in the Module 1.0
 * container behaves identically. The new methods are purely additive, so
 * no existing binding or resolution changes behavior.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, \Closure|string> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $singletons = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, string> alias => target id */
    private array $aliases = [];

    /** @var array<string, list<string>> tag => list of ids */
    private array $tags = [];

    /** @var array<string, \Closure> ids registered as lazy */
    private array $lazy = [];

    /**
     * Identifiers currently mid-resolution, used to detect cycles.
     *
     * @var array<string, true>
     */
    private array $building = [];

    public function bind(string $id, \Closure|string $concrete): void
    {
        $this->bindings[$id] = $concrete;
        unset($this->singletons[$id], $this->resolved[$id], $this->lazy[$id]);
    }

    public function singleton(string $id, \Closure|string $concrete): void
    {
        $this->bindings[$id] = $concrete;
        $this->singletons[$id] = true;
        unset($this->resolved[$id], $this->lazy[$id]);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->resolved[$id] = $instance;
        $this->singletons[$id] = true;
    }

    public function alias(string $alias, string $target): void
    {
        if ($alias === $target) {
            throw new ContainerException(sprintf('An alias cannot point to itself ("%s").', $alias));
        }

        $this->aliases[$alias] = $target;
    }

    public function tag(string $id, string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }

        if (!in_array($id, $this->tags[$tag], true)) {
            $this->tags[$tag][] = $id;
        }
    }

    public function tagged(string $tag): array
    {
        $resolved = [];

        foreach ($this->tags[$tag] ?? [] as $id) {
            $resolved[] = $this->get($id);
        }

        return $resolved;
    }

    public function lazy(string $id, \Closure $concrete): void
    {
        $this->lazy[$id] = $concrete;
        unset($this->resolved[$id]);
    }

    public function has(string $id): bool
    {
        $id = $this->aliases[$id] ?? $id;

        return isset($this->bindings[$id])
            || isset($this->resolved[$id])
            || isset($this->lazy[$id])
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        $id = $this->aliases[$id] ?? $id;

        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        if (isset($this->lazy[$id])) {
            return new LazyProxy($this->lazy[$id]);
        }

        $value = $this->resolve($id);

        if (isset($this->singletons[$id])) {
            $this->resolved[$id] = $value;
        }

        return $value;
    }

    /**
     * @throws NotFoundException
     * @throws ContainerException
     */
    private function resolve(string $id): mixed
    {
        if (isset($this->bindings[$id])) {
            $concrete = $this->bindings[$id];

            if ($concrete instanceof \Closure) {
                return $concrete($this);
            }

            return $this->build($concrete);
        }

        if (class_exists($id)) {
            return $this->build($id);
        }

        throw NotFoundException::forIdentifier($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     *
     * @throws ContainerException
     * @throws CircularDependencyException
     */
    private function build(string $class): object
    {
        if (isset($this->building[$class])) {
            throw CircularDependencyException::forChain(array_keys($this->building), $class);
        }

        $this->building[$class] = true;

        try {
            $object = $this->instantiate($class);
        } finally {
            unset($this->building[$class]);
        }

        return $object;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     *
     * @throws ContainerException
     */
    private function instantiate(string $class): object
    {
        try {
            $reflector = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw new ContainerException(
                sprintf('Cannot reflect class "%s": %s', $class, $e->getMessage()),
                0,
                $e
            );
        }

        if (!$reflector->isInstantiable()) {
            throw new ContainerException(sprintf(
                'Class "%s" is not instantiable (abstract class or interface with no binding).',
                $class
            ));
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $dependencies[] = $this->resolveParameter($class, $parameter);
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * @throws ContainerException
     */
    private function resolveParameter(string $class, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot resolve constructor parameter "$%s" for class "%s": it has no type-hint, no default value, and is not nullable.',
            $parameter->getName(),
            $class
        ));
    }
}

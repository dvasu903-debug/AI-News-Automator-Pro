<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Container;
use AINewsAutomator\Core\Exceptions\CircularDependencyException;
use AINewsAutomator\Core\Exceptions\ContainerException;
use AINewsAutomator\Core\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function test_bind_resolves_a_fresh_instance_every_call(): void
    {
        $this->container->bind('counter', static fn () => new \stdClass());

        $first = $this->container->get('counter');
        $second = $this->container->get('counter');

        $this->assertNotSame($first, $second);
    }

    public function test_singleton_resolves_the_same_instance_every_call(): void
    {
        $this->container->singleton('counter', static fn () => new \stdClass());

        $first = $this->container->get('counter');
        $second = $this->container->get('counter');

        $this->assertSame($first, $second);
    }

    public function test_instance_registers_and_returns_the_exact_object(): void
    {
        $object = new \stdClass();
        $object->marker = 'unique';

        $this->container->instance('thing', $object);

        $this->assertSame($object, $this->container->get('thing'));
    }

    public function test_has_returns_true_for_bound_identifiers(): void
    {
        $this->container->bind('bound-thing', static fn () => new \stdClass());

        $this->assertTrue($this->container->has('bound-thing'));
    }

    public function test_has_returns_true_for_existing_classes_even_without_explicit_binding(): void
    {
        $this->assertTrue($this->container->has(\stdClass::class));
    }

    public function test_has_returns_false_for_unknown_identifiers(): void
    {
        $this->assertFalse($this->container->has('totally-unregistered-thing'));
    }

    public function test_get_throws_not_found_exception_for_unbound_non_class_identifier(): void
    {
        $this->expectException(NotFoundException::class);

        $this->container->get('does-not-exist-and-is-not-a-class');
    }

    public function test_autowires_a_class_with_no_constructor(): void
    {
        $instance = $this->container->get(FixtureWithNoConstructor::class);

        $this->assertInstanceOf(FixtureWithNoConstructor::class, $instance);
    }

    public function test_autowires_a_class_with_typed_constructor_dependencies(): void
    {
        $instance = $this->container->get(FixtureWithDependency::class);

        $this->assertInstanceOf(FixtureWithDependency::class, $instance);
        $this->assertInstanceOf(FixtureWithNoConstructor::class, $instance->dependency);
    }

    public function test_autowiring_uses_explicit_interface_bindings(): void
    {
        $this->container->singleton(
            FixtureInterface::class,
            static fn () => new FixtureImplementation()
        );

        $instance = $this->container->get(FixtureWithInterfaceDependency::class);

        $this->assertInstanceOf(FixtureImplementation::class, $instance->dependency);
    }

    public function test_throws_when_an_unbound_interface_dependency_cannot_be_resolved(): void
    {
        $this->expectException(ContainerException::class);

        $this->container->get(FixtureWithInterfaceDependency::class);
    }

    public function test_uses_default_value_for_unresolvable_scalar_parameter(): void
    {
        $instance = $this->container->get(FixtureWithScalarDefault::class);

        $this->assertSame('default-value', $instance->label);
    }

    public function test_bound_string_pointing_at_another_class_resolves_that_class(): void
    {
        $this->container->bind(FixtureInterface::class, FixtureImplementation::class);

        $instance = $this->container->get(FixtureInterface::class);

        $this->assertInstanceOf(FixtureImplementation::class, $instance);
    }

    public function test_alias_resolves_to_target(): void
    {
        $this->container->singleton('real', static fn () => new \stdClass());
        $this->container->alias('nickname', 'real');

        $this->assertSame($this->container->get('real'), $this->container->get('nickname'));
    }

    public function test_alias_to_itself_throws(): void
    {
        $this->expectException(ContainerException::class);
        $this->container->alias('x', 'x');
    }

    public function test_tagged_returns_all_tagged_services(): void
    {
        $this->container->bind('a', static fn () => new \stdClass());
        $this->container->bind('b', static fn () => new \stdClass());
        $this->container->tag('a', 'group');
        $this->container->tag('b', 'group');

        $this->assertCount(2, $this->container->tagged('group'));
    }

    public function test_tagged_returns_empty_array_for_unknown_tag(): void
    {
        $this->assertSame([], $this->container->tagged('nonexistent'));
    }

    public function test_lazy_returns_proxy_and_defers_construction(): void
    {
        $built = false;
        $this->container->lazy('svc', function () use (&$built) {
            $built = true;
            return new FixtureWithScalarDefault('lazy-built');
        });

        $proxy = $this->container->get('svc');
        $this->assertFalse($built, 'Construction should be deferred until first use.');

        // Touching a property forces resolution.
        $label = $proxy->label;
        $this->assertTrue($built);
        $this->assertSame('lazy-built', $label);
    }

    public function test_circular_dependency_is_detected(): void
    {
        $this->expectException(CircularDependencyException::class);
        $this->container->get(CircularA::class);
    }
}

// --- Fixtures -------------------------------------------------------------

final class FixtureWithNoConstructor
{
}

final class FixtureWithDependency
{
    public function __construct(public readonly FixtureWithNoConstructor $dependency)
    {
    }
}

interface FixtureInterface
{
}

final class FixtureImplementation implements FixtureInterface
{
}

final class FixtureWithInterfaceDependency
{
    public function __construct(public readonly FixtureInterface $dependency)
    {
    }
}

final class FixtureWithScalarDefault
{
    public function __construct(public readonly string $label = 'default-value')
    {
    }
}

final class CircularA
{
    public function __construct(public readonly CircularB $b)
    {
    }
}

final class CircularB
{
    public function __construct(public readonly CircularA $a)
    {
    }
}

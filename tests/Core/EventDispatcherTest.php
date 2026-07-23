<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Contracts\StoppableEventInterface;
use AINewsAutomator\Core\Events\AbstractEvent;
use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadata;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    private function makeEvent(): SampleEvent
    {
        return new SampleEvent(new EventMetadata(
            eventId: 'evt-1',
            timestamp: 1_700_000_000,
            correlationId: 'corr-1',
            sourceModule: 'Test',
        ));
    }

    public function test_dispatch_invokes_registered_listener(): void
    {
        $dispatcher = new EventDispatcher();
        $called = false;

        $dispatcher->addListener(SampleEvent::class, function () use (&$called): void {
            $called = true;
        });

        $dispatcher->dispatch($this->makeEvent());

        $this->assertTrue($called);
    }

    public function test_dispatch_returns_same_instance(): void
    {
        $dispatcher = new EventDispatcher();
        $event = $this->makeEvent();

        $this->assertSame($event, $dispatcher->dispatch($event));
    }

    public function test_listeners_run_in_descending_priority_order(): void
    {
        $dispatcher = new EventDispatcher();
        $order = [];

        $dispatcher->addListener(SampleEvent::class, function () use (&$order): void {
            $order[] = 'low';
        }, 5);
        $dispatcher->addListener(SampleEvent::class, function () use (&$order): void {
            $order[] = 'high';
        }, 20);
        $dispatcher->addListener(SampleEvent::class, function () use (&$order): void {
            $order[] = 'default';
        }, 10);

        $dispatcher->dispatch($this->makeEvent());

        $this->assertSame(['high', 'default', 'low'], $order);
    }

    public function test_listener_on_interface_receives_implementing_event(): void
    {
        $dispatcher = new EventDispatcher();
        $called = false;

        $dispatcher->addListener(StoppableEventInterface::class, function () use (&$called): void {
            $called = true;
        });

        $dispatcher->dispatch($this->makeEvent());

        $this->assertTrue($called);
    }

    public function test_stop_propagation_halts_remaining_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $secondCalled = false;

        $dispatcher->addListener(SampleEvent::class, function (SampleEvent $e): void {
            $e->stopPropagation();
        }, 20);
        $dispatcher->addListener(SampleEvent::class, function () use (&$secondCalled): void {
            $secondCalled = true;
        }, 10);

        $dispatcher->dispatch($this->makeEvent());

        $this->assertFalse($secondCalled);
    }

    public function test_event_exposes_metadata(): void
    {
        $event = $this->makeEvent();

        $this->assertSame('evt-1', $event->eventId());
        $this->assertSame('corr-1', $event->correlationId());
        $this->assertSame('Test', $event->sourceModule());
    }
}

final class SampleEvent extends AbstractEvent
{
}

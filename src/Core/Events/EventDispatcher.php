<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Events;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\StoppableEventInterface;

/**
 * Priority-ordered, class-hierarchy-aware event dispatcher.
 *
 * A listener registered for an interface or parent class is invoked
 * for any event that implements/extends it, not just exact-class
 * matches — so a generic "log every pipeline event" listener can
 * register against a shared PipelineEventInterface once, rather than
 * once per concrete event class.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<class-string, list<array{priority: int, listener: callable}>>
     */
    private array $listeners = [];

    public function addListener(string $eventClass, callable $listener, int $priority = 10): void
    {
        $this->listeners[$eventClass][] = [
            'priority' => $priority,
            'listener' => $listener,
        ];
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listenersFor($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * @return list<callable>
     */
    private function listenersFor(object $event): array
    {
        $eventClasses = array_merge(
            [$event::class],
            array_values(class_parents($event) ?: []),
            array_values(class_implements($event) ?: [])
        );

        $entries = [];

        foreach ($eventClasses as $class) {
            foreach ($this->listeners[$class] ?? [] as $entry) {
                $entries[] = $entry;
            }
        }

        // Stable sort by descending priority — array_multisort/usort alone
        // isn't guaranteed stable pre-PHP 8.0, but stability has been
        // guaranteed by the language since 8.0, which matches our
        // composer.json PHP ^8.2 requirement.
        usort($entries, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_column($entries, 'listener');
    }
}

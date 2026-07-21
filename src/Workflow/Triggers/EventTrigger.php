<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Triggers;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Workflow\Contracts\TriggerInterface;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;

/**
 * Subscribes a workflow_key to a plugin-internal event class (Core's
 * EventDispatcherInterface — the same dispatcher every module already
 * uses for cross-module communication, not a new mechanism). Modules
 * whose service providers register an EventTrigger subscription during
 * their own boot() cause a matching event dispatch to start a run
 * automatically — Workflow itself never hardcodes which event classes
 * exist; WorkflowServiceProvider only wires the listener plumbing.
 */
final class EventTrigger implements TriggerInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly WorkflowRunner $runner,
    ) {
    }

    public function type(): string
    {
        return 'event';
    }

    /**
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, string $workflowKey): void
    {
        // $event is intentionally unused inside the closure body — this
        // is a coarse-grained "any instance of $eventClass starts a run"
        // subscription by design (see class docblock), not a filter on
        // the event's own data. The parameter itself is kept, not
        // dropped, purely to document the closure's real invocation
        // contract: EventDispatcher always calls it with the dispatched
        // event instance, matching every other listener signature in
        // this codebase.
        $this->events->addListener($eventClass, function (object $event) use ($workflowKey): void {
            $this->runner->run($workflowKey, 'event');
        });
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Contracts;

/**
 * Marker/behavior contract for the four trigger types (Manual, Event,
 * Scheduled, API). A trigger's job is purely "decide when/how a
 * WorkflowRunner::run() call happens" — triggers never execute steps
 * themselves, that's the Runner's job exclusively.
 */
interface TriggerInterface
{
    /** Matches a workflow definition's "trigger.type" field. */
    public function type(): string;
}

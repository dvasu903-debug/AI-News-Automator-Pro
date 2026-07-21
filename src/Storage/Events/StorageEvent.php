<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Events;

use AINewsAutomator\Core\Events\AbstractEvent;

/**
 * Base for Storage's domain events. Extends Core's AbstractEvent so every
 * event carries the standard metadata envelope and flows through Core's
 * EventDispatcher unchanged, exactly like Security's events.
 *
 * Events are emitted only for meaningful state changes on repositories
 * whose writes are relatively low-frequency and where another module
 * plausibly wants to react (Queue lifecycle, Sources, Workflows, Articles,
 * Images). High-frequency telemetry writes (Logs, Audit, Metrics, AI
 * request records) do NOT get a matching domain event per row — Audit
 * already emits its own Security-layer events at the point of the
 * security decision, and emitting a second event per persisted log/audit/
 * metric row would flood the event bus for no consumer benefit.
 */
abstract class StorageEvent extends AbstractEvent
{
}

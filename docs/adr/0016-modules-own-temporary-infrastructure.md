# ADR-0016: Modules Own Temporary Infrastructure Until a Shared Abstraction Exists

**Status:** Accepted · **Cross-cutting** (first applied in Module 5)

## Context

Module 5 needs both scheduling (something must claim and run its queued jobs — no dedicated Scheduler module exists yet) and HTTP retry logic (source-fetch failures aren't AI-provider failures, so reusing AI's `RetryExecutor` would mean either coupling Sources to `AIException` or reopening the frozen AI module to generalize it). Neither is available as a shared abstraction today, but both are genuinely needed for Module 5 to function.

## Decision

A module may build a narrowly-scoped piece of infrastructure for its own use — even when that infrastructure looks similar in shape to something another module already has — rather than either (a) blocking on a shared abstraction that doesn't exist yet, or (b) reopening a frozen module to generalize its internals prematurely.

Two concrete instances, both introduced in Module 5:

- **`SourceSyncScheduler`** — a WP-Cron hook scoped only to `source.fetch`/`source.crawl` job types. Not a general-purpose dispatcher for the whole plugin's queue.
- **`SourceRetryExecutor`** — duplicates AI's exponential-backoff *algorithm* (the actual arithmetic — attempt count, base delay, jitter), but not AI's *architecture* (no separate `RetryPolicyInterface`, no pluggable-strategy layer). It is a single concrete class scoped to `SourceFetchException`/`SourceFetchErrorType`.

Both are explicitly documented, in code and here, as temporary and narrow.

## Consequences

- Module 5 ships without waiting for a hypothetical future Scheduler module or without coupling its HTTP-retry semantics to AI's provider-failure semantics.
- The *duplication* is bounded and honest: a backoff formula (~30 lines) and a single-purpose cron hook, not a re-implementation of a whole subsystem.
- **Extraction trigger, stated explicitly so it isn't missed:** when a *third* module needs materially the same capability (a third thing needing exponential-backoff retry, or a third thing needing scheduled job dispatch), that is the signal to extract a shared abstraction into Core — not before. Two independent implementations of a similar shape is normal and cheap; three is the point where the duplication cost exceeds the abstraction cost.
- A future dedicated Scheduler module is expected to eventually supersede `SourceSyncScheduler`'s cron hook — at which point `SourceSyncScheduler` becomes a thin adapter or is retired, not a permanent parallel system.
- This ADR is the standing answer to "why didn't you just reuse AI's retry system / build the general scheduler now" for any future reviewer — the reasoning doesn't need to be re-derived or, worse, silently reversed by someone assuming the duplication was an oversight.

## Alternatives Considered

- **Block Module 5 until a shared retry/scheduling abstraction exists.** Rejected: no other module currently needs one badly enough to justify designing it speculatively — premature generalization for a hypothetical second/third consumer is its own anti-pattern.
- **Reopen AI or Core to generalize retry logic now.** Rejected: violates the freeze on Modules 1–4 for a two-consumer case that doesn't yet justify the abstraction cost, per the extraction trigger above.
- **Give Sources full ownership of a plugin-wide generic scheduler.** Rejected: scope creep — Module 5 is Source Connectors, not Scheduling; a real Scheduler module deserves its own design pass (retry-at-the-job-level, concurrency control across workers, WP-Cron reliability workarounds) rather than being backed into as a side effect of Module 5.

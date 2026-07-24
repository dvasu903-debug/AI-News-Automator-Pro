# ADR-0017: Extraction Trigger Met But Deferred

**Status:** Accepted · **Module:** 6 (decision applies at Module 7's future retry needs)

## Context

ADR-0016 established that a third module needing materially the same retry-with-backoff capability (AI's `RetryExecutor`, Sources' `SourceRetryExecutor` are the first two) is the signal to extract a shared abstraction into Core. Module 7 (Workflow Engine, not yet built) will need step-level retry — the third instance, meeting that exact trigger.

Separately, this session's explicit instruction is: do not modify Modules 1–5. Extracting a shared retry abstraction now would require either modifying AI's or Sources' files to depend on the new shared class, or leaving them as-is while only new modules use the shared version (a partial, inconsistent extraction).

## Decision

The extraction trigger is **met but explicitly deferred**. When Module 7 is built, it will receive its own narrow `Workflow\Retry\WorkflowStepRetryExecutor` — a fourth small, narrow implementation, following the exact same pattern ADR-0016 established (duplicate the algorithm, not the architecture) — rather than triggering an extraction into Core.

This is not a reversal of ADR-0016's reasoning. The reasoning still holds: three independent implementations of a similar shape is the point where duplication cost would normally exceed abstraction cost. What's different here is that the *freeze on completed modules* is a harder constraint than the extraction trigger — the freeze was set by explicit instruction for this session, while the extraction trigger was ADR-0016's own internal heuristic for *when it would be safe and worthwhile* to extract, assuming no other constraint blocked it.

## Consequences

- By the time Module 7 exists, there will be three (soon four, if Sources or AI ever need a second retry-shaped capability) narrow, independently-maintained retry implementations: `AI\Manager\ExponentialBackoffRetryPolicy`, `Sources\Retry\SourceRetryExecutor`, and `Workflow\Retry\WorkflowStepRetryExecutor`. This is more duplication than ADR-0016 anticipated tolerating — accepted consciously, not accidentally.
- **A future, explicitly-approved refactor pass** — not automatic, not assumed — is the correct place to perform the extraction ADR-0016 anticipated, once modifying Modules 1–5 (or whichever hold the narrow implementations at that time) is back in scope. This ADR is the record of *why* it wasn't done now, so that future pass doesn't have to rediscover the reasoning or wonder whether the duplication was an oversight.
- This ADR itself grants no authorization to modify Modules 1–5. It only records that the extraction trigger fired and was deliberately not acted on.

## Alternatives Considered

- **Extract now, modify AI and/or Sources to depend on the shared version.** Rejected: directly violates the explicit "do not modify Modules 1–5" instruction for this session.
- **Extract now as a new Core class, but leave AI/Sources on their old implementations (partial extraction).** Rejected: creates a worse outcome than either full extraction or full deferral — a shared abstraction exists but isn't actually shared, adding a class without removing any duplication, while creating the false impression the ADR-0016 trigger was acted on.
- **Silently ignore ADR-0016's trigger with no record.** Rejected: exactly the failure mode ADR-0016 itself was written to prevent — a future maintainer re-deriving or accidentally reversing a deliberate decision without the reasoning available.

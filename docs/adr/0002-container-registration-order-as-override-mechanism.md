# ADR-0002: Container Registration Order as the Rebinding Mechanism

**Status:** Accepted · **Modules:** 1.1, 3, 4

## Context

Later modules need to supersede earlier modules' default implementations without editing frozen files — e.g. Storage replacing Core's `OptionBackedLogger` and Security's option-backed audit/metrics with table-backed implementations (Module 3), and AI reusing Storage's exact `MigrationRunner`/`MigrationRecorder` instances for its own tables (Module 4).

## Decision

`Container::singleton()`/`bind()` simply overwrite whatever was previously bound for the same identifier. Every provider's `register()` phase runs, for every provider in `ModuleManifest` order, *before* any provider's `boot()` phase runs. A later-registered module's binding for a shared interface therefore always wins, and — critically — nothing has been *resolved* yet at register-time, so there is no stale-instance risk.

This makes `ModuleManifest`'s provider order semantically meaningful, not just cosmetic: Core → Security → Storage → AI is the dependency order, and it is also the override-precedence order.

## Consequences

- A later module can supersede an earlier module's default binding with zero edits to the earlier module's files — verified explicitly in each Architecture Verification Report.
- `ModuleManifest`'s ordering comment must stay accurate; reordering providers changes which binding wins for any shared interface.
- Reusability extends beyond bindings: since classes like `MigrationRunner` take their working data (the migration list) as a method parameter rather than as constructor-baked state, a later module can call the *same singleton instance* Storage registered, with its own data, without any new instantiation.

## Alternatives Considered

- **Explicit override registry** (a dedicated "supersede this binding" API). Rejected: adds a second mechanism alongside the container's normal bind/singleton semantics for no behavioral gain — the natural last-write-wins behavior of `register()` running in manifest order already does this correctly.

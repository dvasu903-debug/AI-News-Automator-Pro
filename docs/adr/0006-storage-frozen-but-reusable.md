# ADR-0006: Storage Is Frozen From Modification, Not From Reuse

**Status:** Accepted · **Modules:** 3, 4

## Context

Module 4 (AI) needed two new tables (`ana_prompt_templates`, `ana_prompt_history`) after Storage was already frozen. A literal reading of "frozen" could wrongly imply no future module can ever get new tables without reopening Storage.

## Decision

"Frozen" means Storage's *files* are not edited. It does not mean Storage's *classes* can't be reused. `Connection`, `MigrationRunner`, `MigrationRecorder`, `AbstractMigration`, `SchemaBuilder`, and `AbstractRepository` are generic, reusable infrastructure — any future module instantiates its own migration list (`AiMigrationManifest`, for AI's own tables) and calls into these same classes, or even the same bound singleton instances (`MigrationRunner`/`MigrationRecorder` take their migration list as a per-call parameter, not baked-in state), without touching a single Storage file.

## Consequences

- Module 4 shipped two new tables with zero Storage file changes — verified in the Module 4 build and re-verified in the cross-module Architecture Verification Report.
- All modules' migrations, regardless of which module owns the tables, are tracked in the same shared `ana_schema_migrations` table, since `MigrationRecorder` is generic and version strings are unique by convention across the whole plugin.
- Every future module (5+) needing its own tables follows the identical pattern: its own `XMigrationManifest`, its own `AbstractMigration` subclasses, its own repository extending Storage's `AbstractRepository` — never a fork or copy of Storage's mechanics.

## Alternatives Considered

- **A new migration system per module.** Rejected: exactly the duplicated infrastructure the whole plugin's design principles exist to prevent.
- **Reopening Storage for each new module's tables.** Rejected: defeats the purpose of freezing a module at all, and would make "freeze" a hollow guarantee.

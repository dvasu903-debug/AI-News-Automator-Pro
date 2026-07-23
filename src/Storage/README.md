# Module 3: Storage

The single source of truth for all persistent data in the AI Publishing Engine. No business logic anywhere in the plugin calls `$wpdb` directly — everything goes through a repository, and every repository goes through `ConnectionInterface`.

Full audit, design rationale, and the approved architecture are in `../../planning/STORAGE_DESIGN.md`. This README documents what was actually built.

## Architecture at a glance

```
Business logic
      │  (constructor-injected interfaces only)
      ▼
Repository (implements a *Interface, extends AbstractRepository)
      │  (validates, hydrates/dehydrates entities)
      ▼
QueryBuilder (composes parameterized SQL — never touches the DB)
      │
      ▼
Connection (the ONLY class that touches $wpdb; always $wpdb->prepare())
      │
      ▼
MySQL / MariaDB (InnoDB required — see health check)
```

## Database layer

- **`Connection`** — every method (`select`, `insert`, `update`, `delete`, `insertMany`, `upsertIncrement`, `statement`) routes through `$wpdb->prepare()` when parameters are present. String concatenation of values into SQL never happens.
- **`QueryBuilder`** — fluent, immutable (each `where()`/`orderBy()`/etc. returns a new instance), and testable *without a database*: `toSql()` returns the SQL + params tuple it would execute, which is what the `QueryBuilderTest` suite asserts against directly.
- **`TransactionManager`** — `transactional(callable)` is the primary API; nested calls use `SAVEPOINT`/`ROLLBACK TO SAVEPOINT`. Honest caveat: MySQL DDL (`CREATE TABLE`) causes an implicit commit regardless of an open transaction, so the transactional wrap around schema-creation migrations is harmless but not genuinely atomic across multiple DDL statements — stated plainly rather than implied otherwise.
- **`SchemaInspector`** — table/engine/index introspection, backing the health check and migration auto-detection.

## Migration system

9 migrations, applied in order by `MigrationRunner`, tracked in `ana_schema_migrations`:

| Version | Creates |
|---|---|
| `...0001` | `ana_schema_migrations` (bootstrap) |
| `...0002` | `ana_queue` + `ana_jobs` |
| `...0003` | `ana_logs` |
| `...0004` | `ana_audit` |
| `...0005` | `ana_metrics` + `ana_metric_counters` |
| `...0006` | `ana_sources` |
| `...0007` | `ana_workflows` |
| `...0008` | `ana_ai_requests` |
| `...0009` | `ana_images` |

All table creation goes through `dbDelta()` (via `SchemaBuilder`), the WordPress-idiomatic idempotent create/alter mechanism. `MigrationManifest` is an explicit ordered list (mirroring `ModuleManifest`'s own pattern) rather than directory-scanning. **Automatic upgrade detection**: on every `plugins_loaded`, a cheap pending-migration check runs and applies anything missing — catches "site files were upgraded without reactivating the plugin," not just fresh activation. **Rollback**: fully meaningful for data migrations (genuine inverse operation); schema-creation migrations default to a documented no-op, since `dbDelta` has no native reversal and a hand-rolled `DROP TABLE` is a correctness risk this module doesn't paper over.

## Schema / ER diagram

See `../../planning/STORAGE_DESIGN.md` §2.5–2.6 for the full column-by-column schema and Mermaid ER diagram. Summary of the two deliberate deviations from "one table per concern":

- **`ana_queue`** (active jobs only, kept small) + **`ana_jobs`** (completed/failed/cancelled history, retention-pruned) — a job moves atomically between them on completion via `QueueRepository`, inside a transaction.
- **Articles have no dedicated table.** `ArticleRepository` wraps `wp_insert_post`/`wp_update_post`/postmeta — introducing a parallel table would create two sources of truth for the same content and break WordPress-native features (revisions, editor). **Settings also stay on `wp_options`** via `SettingsRepository` — small, read-heavy, admin-form-driven config is exactly what `wp_options` is built for; only the access pattern is wrapped behind an interface, not moved to a new table.

No table uses a formal `FOREIGN KEY` constraint (matches WordPress core's own convention — `wp_postmeta`/`wp_options` use none either). Referential integrity is checked by the health check's orphan detection instead.

## Repository map

| Interface | Concrete | Table | Notes |
|---|---|---|---|
| `SettingsRepositoryInterface` | `SettingsRepository` | `wp_options` | Deliberately not table-backed |
| `QueueRepositoryInterface` | `QueueRepository` | `ana_queue` | Atomic claim (`FOR UPDATE`), transactional completion move |
| `JobHistoryRepositoryInterface` | `JobHistoryRepository` | `ana_jobs` | Written only via `QueueRepository`'s move, never directly |
| `LogRepositoryInterface` | `LogRepository` | `ana_logs` | Backs `TableBackedLogger` |
| `Security\Contracts\AuditLogRepositoryInterface` | `AuditRepository` | `ana_audit` | **Rebinds Security's Module 2 interface** |
| `MetricsRepositoryInterface` | `MetricsRepository` | `ana_metrics` + `ana_metric_counters` | Atomic upsert increments |
| `SourceRepositoryInterface` | `SourceRepository` | `ana_sources` | Emits `SourceSavedEvent` |
| `WorkflowRepositoryInterface` | `WorkflowRepository` | `ana_workflows` | Emits `WorkflowSavedEvent` |
| `AiRequestRepositoryInterface` | `AiRequestRepository` | `ana_ai_requests` | High-frequency; no domain event |
| `ImageRepositoryInterface` | `ImageRepository` | `ana_images` | Orphan detection via anti-join |
| `ArticleRepositoryInterface` | `ArticleRepository` | `wp_posts` + postmeta | No new table |

Plus two **rebind adapters** (not separate domain repositories, but part of requirement 2):

- **`TableBackedLogger`** implements Core's `LoggerInterface` — supersedes `OptionBackedLogger`.
- **`TableBackedSecurityMetrics`** implements Security's `SecurityMetricsInterface` — supersedes the option-backed `SecurityMetrics`, fixing the exact read-modify-write race identified in the Module 3 audit (W2) via `MetricsRepository`'s atomic `INSERT ... ON DUPLICATE KEY UPDATE`.

## The rebinding mechanism (Audit, Logger, Metrics — requirement 2)

`StorageServiceProvider` is positioned **after** Core and Security in `ModuleManifest`. During the register phase (which runs for every provider, in order, before any `boot()`), Storage calls `$container->singleton()` again for `LoggerInterface`, `AuditLogRepositoryInterface`, and `SecurityMetricsInterface` — the container simply overwrites each binding. Since nothing is resolved until later, there's no stale-instance risk. **Neither Core's nor Security's files were touched** — verified: `grep` for edits to those modules' source shows none beyond the single designed extension point (`ModuleManifest`'s provider array, exactly as when Security itself was added in Module 2).

## Domain events (requirement 1)

Emitted for meaningful, relatively low-frequency state changes where another module plausibly wants to react: `JobEnqueuedEvent`, `JobCompletedEvent`, `JobFailedEvent`, `SourceSavedEvent`, `WorkflowSavedEvent`, `ArticleDraftCreatedEvent`, `ArticleApprovedEvent`, `ImageRecordedEvent`. **Deliberately not emitted** for every `ana_logs`/`ana_audit`/`ana_metrics`/`ana_ai_requests` write — those are high-frequency telemetry, and a matching event per row would flood the event bus for no consumer benefit (Audit already emits its own event at the point of the security decision, one layer up in Security).

## Validation (requirement 6)

`AbstractRepository::validate()` is the single choke point every write passes through before persistence — overridden per repository for domain rules (required fields, known enum/type values, sane ranges). `QueueRepository` rejects empty job types and out-of-range priorities; `SourceRepository`/`AiRequestRepository` reject unrecognized type/status values; `LogRepository`/`AuditRepository` reject unrecognized log levels (via the shared `LogLevelValidator`, avoiding a duplicated level list). Failures throw `ValidationException` with field-level error detail.

## Bulk operations (requirement 7)

`Connection::insertMany()` builds one multi-row `INSERT ... VALUES (...),(...),(...)` (fully parameterized) instead of N round-trips. `AbstractRepository::insertRows()` validates every entity **before** writing any — confirmed by test (`AbstractRepositoryTest::test_bulk_insert_validates_every_entity_before_writing_any`). `QueueRepository::bulkEnqueue()` and `AbstractRepository`'s bulk scaffolding are available to every repository that needs it.

## Extension points (requirement 8)

- **Query profiling**: `QueryProfilerInterface`, default binding `NullQueryProfiler` (true no-op). `Connection` wraps every execution through it regardless of which implementation is bound — a future Monitoring module binds a real profiler with zero changes to `Connection` or any repository.
- **Archiving**: `ArchiverInterface` defined, **no default binding registered** — genuinely not implemented in Module 3, an open seam for cold-storage archiving as a complement/alternative to retention deletion.

## Retention

`RetentionPolicy` (generic, works over any `PurgeableInterface` repository) + `RetentionCleanupJob`. Four default policies (logs 30d, audit 180d, job history 60d, metric events 90d — all configurable via `ConfigRepositoryInterface`). Batched `DELETE ... LIMIT N` in a loop (`BatchPurger`, shared to avoid duplicating this logic across four repositories) — never one unbounded `DELETE`. `ana_metric_counters` (running totals) is explicitly never purged. Wiring `RetentionCleanupJob::run()` to an actual recurring schedule is deferred to Module 7 (Scheduler) — the class exists now for that module to schedule.

## Export / Import / Backup

Implemented for the low-volume, config-shaped data where "backup" is unambiguous: `SourcesExporter`/`SourcesImporter`, `WorkflowsExporter`/`WorkflowsImporter`, orchestrated by `BackupManager`. High-volume tables (logs, audit, AI requests) remain an open interface (`ExporterInterface`/`ImporterInterface`) without a concrete implementation — honestly out of scope until real usage data informs what "backup" should mean for a queue/audit table.

## Health checks

`StorageHealthCheck` (reuses Security's `HealthCheckResult` shape): table existence, migration status, storage engine (InnoDB required for transactions), index presence, a query-performance canary, and orphaned-image detection.

## Performance decisions

- **Queue/history split** keeps the hot "find next job" table small regardless of total historical job volume.
- **Lazy column selection**: `QueryBuilder::select()` lets a caller exclude large `LONGTEXT` payload/result columns from list views.
- **Read-through caching** is explicitly NOT implemented in Module 3 for any table — every repository queries live. This was cut from the original design scope to keep the module reviewable; the design doc's caching strategy (§2.11) remains the plan for a later pass once real usage patterns justify which reads are actually hot.
- **Explicitly out of scope**: MySQL partitioning, read replicas — most WordPress hosting can't operate either, and the queue/history split plus retention pruning is the scoped answer to "millions of rows."

## Testing

**What's genuinely unit-tested here (143 methods across 23 files, Storage-specific: 42 methods / 8 files)**:
- `QueryBuilder` SQL generation (every filter operator, sorting, pagination, immutability, identifier-injection rejection) — no database involved.
- Entity DTO round-trips (`fromRow`/`toRow`), `EntityDates` edge cases.
- The **real** `MigrationRecorder`/`MigrationRunner`/`SchemaInspector` against `FakeWpdb`, an in-memory `$wpdb` double — not mocks of these classes, but the actual classes exercised against a fake backend. Covers: bootstrap-before-tracking-table-exists, ordering pending migrations by version regardless of input order, skipping already-applied migrations, and `hasPending()`.
- `AbstractRepository`'s shared scaffolding via a fixture entity/repository — validate-before-insert, bulk-insert-validates-before-any-write, find/not-found.
- `RetentionPolicy`, `LogLevelValidator`, query value objects (`Filter`, `SortOrder`, `PageResult`).

**Honestly out of scope here, requiring a real WordPress+MySQL environment** (documented, not runnable in this sandbox): full CRUD against real tables for the other 8 repositories, `dbDelta` execution itself, real transaction rollback/savepoint behavior, `ImageRepository::findOrphans()`'s actual join, large-dataset (10k+ row) pagination/index-usage tests, and performance benchmarks. `FakeWpdb`'s own docblock states its narrow, deliberate scope (single-table, simple WHERE only — not a SQL engine) so its limits are visible to whoever extends these tests next.

**Same execution limitation as every prior module**: no PHP runtime, no MySQL, no network for `composer install` in this environment. Validation performed here: brace/paren balance across all 202 source files, PSR-4 namespace-to-path correctness, one-type-per-file compliance, every import/inline-reference resolution, and manual constructor-signature cross-checks between `StorageServiceProvider`'s wiring and every class it constructs. Two real bugs were caught and fixed during this cross-check (an `instanceof`-against-concrete-class anti-pattern in `QueueRepository`, and an `activate()` method that was an empty stub despite its own comment describing what it should do) — run `composer test` locally for the behavioral confirmation this sandbox cannot provide.

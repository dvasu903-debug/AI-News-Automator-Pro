# Release Notes

## 2.0.0-dev — Module 7 (Workflow Engine) frozen

**Date:** 2026-07-21

### What's new

Module 7 adds a full workflow orchestration engine to AI News Automator
Pro: versioned, write-once workflow definitions; a runner supporting
linear execution, conditional branching, deferred (queue-backed) steps,
approval gates, and rollback of completed steps on failure; a
WP-Cron-driven scheduler; and a REST API for triggering runs and
deciding approvals.

### Fixed

- **Every workflow action was unusable at runtime** despite passing all
  automated tests — `ActionRegistryInterface` was bound as a fresh
  instance per resolution instead of a shared singleton, so the
  populated action registry and the one `WorkflowRunner` actually used
  were two different objects. Found during runtime validation; fixed.
- **A crashed queue worker orphaned its job permanently** — nothing in
  the system ever reclaimed a job left `processing` by a worker that
  never returned. `QueueRepository::claimNextForWorker()` now
  automatically reclaims stale-locked jobs (configurable timeout,
  default 15 minutes), correctly counting each crash as a retry attempt
  so a repeatedly-crashing job still respects `max_attempts` rather than
  looping forever.
- Plugin uninstall previously failed to remove Module 7's own database
  tables (a table-prefix bug in hardcoded names); now derives table
  names the same way the migrations that create them do.
- Removed four redundant repository constructors and resolved all
  actionable PHPCS findings (security/SQL/correctness sniffs kept
  active; WordPress-core-era style conventions — tabs, K&R braces, long
  array syntax — excluded as incompatible with this project's PSR-4/SOLID
  architecture, not disabled wholesale).

### Validated

Full runtime validation against a real WordPress + MySQL + WP-Cron
install, not just the unit test suite: migration execution and
idempotency, uninstall scope, cron scheduling, deferred-job resume via
the real queue, rollback behavior, the approval REST API (with real
HTTP requests and Application Password auth), workflow version pinning,
queue recovery after a simulated worker crash, and the real event
dispatch order. Full report:
`docs/verification/2026-07-21-module-7-runtime-validation.md`.

### Known, documented, non-blocking findings

- This host's persistent object cache can serve stale role/capability
  data after a capability-affecting lifecycle event (e.g. uninstall);
  `wp cache flush` resolves it. Not a plugin defect.
- The `plugins_loaded`-registered self-healing migration check does not
  reliably fire when a module's `boot()` is invoked manually outside a
  normal WordPress request (e.g. via `wp eval`); the real
  activation-hook path is unaffected and remains the reliable mechanism.

### Compatibility

No breaking changes to Modules 1–6. All 25 database tables across the
plugin's 7 modules confirmed present and correctly scoped through this
validation cycle.

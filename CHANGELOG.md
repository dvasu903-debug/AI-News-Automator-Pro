# Changelog

All notable changes to AI News Automator Pro are documented here. The
project is pre-release; entries below cover the 2.0.0-dev line.

## [Unreleased — 2.0.0-dev]

### Fixed
- **Workflow (Module 7):** `ActionRegistryInterface` was container-bound
  via `bind()` instead of `singleton()`, so `WorkflowRunner` always
  received a fresh, empty action registry at runtime and every action
  type appeared unregistered ("No action registered for type ...").
  Found during Module 7 runtime validation Item 10 — unreachable by unit
  tests, which construct the runner directly. (`WorkflowServiceProvider`)
- **Storage (Module 3, authorized post-freeze fix):**
  `QueueRepository::claimNextForWorker()` now reclaims stale
  `processing` jobs whose lock has exceeded a filterable timeout
  (`ai_news_automator_queue_stale_lock_timeout`, default 900s). A worker
  crash previously orphaned its job in `processing` forever. Each
  reclaim counts as a failed attempt, so repeat-crashing jobs still
  exhaust `max_attempts` and fail into job history. Found and
  empirically proven during Module 7 runtime validation Item 14.
- **Workflow (Module 7):** `WorkflowServiceProvider::uninstall()` used
  hardcoded table names missing the WordPress table prefix, so uninstall
  silently dropped nothing. Now derives names via
  `SchemaBuilder::tableName()`, identically to the migrations that
  create them. Found during the PHPCS remediation review.
- **Workflow (Module 7):** removed four redundant repository
  constructors (flagged by `Generic.CodeAnalysis.UselessOverridingMethod`).

### Validation
- Module 7 runtime validation executed against a real
  WordPress + MySQL + WP-Cron environment (Items 7–14 complete at time
  of writing; see docs/verification/2026-07-21-module-7-runtime-validation.md
  for the full evidence trail, including two environment findings:
  stale object-cache serving pre-grant role capabilities, and the
  `plugins_loaded` self-healing migration path not firing under manual
  `wp eval` boot).

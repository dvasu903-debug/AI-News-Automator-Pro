# Changelog

All notable changes to AI News Automator Pro are documented here. The
project is pre-release; entries below cover the 2.0.0-dev line.

## [Unreleased — 2.0.0-dev]

### Added
- **Publishing (Module 8, Milestone 2):** Publishing Profile management —
  `PublishingProfileService`/`PublishingProfileRepository` CRUD,
  single-writer `is_default` promotion/demotion, structural-only
  `approval_mode` validation (no invented fixed value list),
  `DuplicateSlugException`/`DuplicateNameException` uniqueness
  enforcement, and `Migration_20260722100004_AddIsDefaultToPublishingProfilesTable`
  (additive-only, appended fourth in `PublishingMigrationManifest`).
  `PublishingServiceProvider` gains singleton bindings for the new
  repository, validator, and service.

### Fixed
- **Publishing (Module 8, Milestone 2):** `PublishingProfileRepository::markDefault()`
  read the current default via an unlocked `SELECT` and demoted only
  that row — under concurrent `markDefault()` calls, two transactions
  could act on the same stale snapshot and leave two `is_default = 1`
  rows. Found via runtime checklist item D12 (parallel-process
  concurrency test against a real database); fixed by demoting via a
  single blanket exact-match `UPDATE ... WHERE is_default = 1`, which
  takes row locks on every currently-default row and serializes
  concurrent callers.
- **Publishing (Module 8, Milestone 2):** `PublishingProfile::configJson()`
  and `PublishingProfileValidator` used raw `json_encode()`, the only
  such calls in `src/` — every other module uses `wp_json_encode()`.
  Switched both for consistency; flagged by PHPCS.
- **CI:** `.github/workflows/build.yml`'s PHPCS step hard-failed the
  build on any finding, which didn't match this project's own
  documented PHPCS policy (`phpcs.xml.dist`'s policy note,
  `scripts/validate-module-7.sh`'s convention: exit 1 is reviewed
  baseline debt, not a blocker). Surfaced by this repository's
  first-ever CI run. PHPCS still always runs and its report is always
  printed; only PHPCS itself failing to execute now fails the build.
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
- Module 8 Milestone 2 (Publishing Profiles) local pipeline (`php -l`,
  PHPUnit, PHPCS) and runtime verification — against a real database
  (MariaDB + WordPress 6.8.3 `wpdb`/`dbDelta` via the production boot
  path) and then a live Hostinger smoke test (plugin activation,
  migrations, `is_default` column, default create/switch,
  `requireDefault()` failure path, admin loads clean) — both passed;
  see docs/verification/2026-07-23-module-8-milestone-2-local-verification.md
  and docs/verification/2026-07-23-module-8-milestone-2-runtime-verification.md.

# Roadmap — AI Publishing Engine (AI News Automator Pro)

Three-layer naming: Architecture (AI Publishing Engine), Product (AI News
Automator Pro), Vertical (News).

## Module status

| Module | Name | Status |
|---|---|---|
| 1 | Core | Frozen |
| 2 | Security | Frozen |
| 3 | Storage | Frozen (one authorized post-freeze fix — see below) |
| 4 | AI Provider Engine | Frozen |
| 5 | Source Connectors | Frozen |
| 6 | Research Engine | Frozen |
| 7 | Workflow Engine | Frozen — 2026-07-21 |
| 8 | Publishing Engine | **Milestone 2 frozen — 2026-07-23** (Milestone 3 next) |

## Module 8 — Milestone 2 (Publishing Profiles) freeze record

**Status: MILESTONE 2 FROZEN — 2026-07-23.** Module 8 as a whole remains
in progress; Milestone 3 (Planner/Validator/Scheduler) has not started.

Milestone 2 adds Publishing Profile management (CRUD, single-writer
default via `is_default`, structural `approval_mode` validation with no
invented enum) on top of Milestone 1's `DraftRepository` foundation.
Full local pipeline (`php -l`, PHPUnit — 490 tests/826 assertions/1
documented incomplete, PHPCS) and runtime verification against a real
database (MariaDB + WordPress 6.8.3 `wpdb`/`dbDelta` via the production
boot path, then a live Hostinger smoke test) both passed. Evidence
trail: `docs/verification/2026-07-23-module-8-milestone-2-local-verification.md`
and `docs/verification/2026-07-23-module-8-milestone-2-runtime-verification.md`.

One genuine concurrency defect was found during runtime verification —
reachable only under real parallel execution, not catchable by the unit
suite's synchronous fakes:

1. `PublishingProfileRepository::markDefault()`'s demote step read the
   current default via an unlocked `SELECT` and demoted only that row —
   under concurrent calls, two transactions could both act on a stale
   snapshot, leaving two `is_default = 1` rows. Fixed by demoting via a
   single blanket exact-match `UPDATE ... WHERE is_default = 1`, which
   takes row locks on every currently-default row and serializes
   concurrent callers. Re-verified: 300+ interleaved concurrent switches
   across four runs, always exactly one default.

Also fixed: the CI workflow's PHPCS step previously hard-failed the
build on any finding, which doesn't match this project's own documented
PHPCS policy (`phpcs.xml.dist`'s policy note, `scripts/validate-module-7.sh`'s
convention) — exit 1 (findings) is now treated as reviewed baseline
debt, not a blocker; only PHPCS itself failing to execute still fails
the build. Workflow-only change; no `src/` files touched.

## Module 7 — freeze record

**Status: FROZEN — 2026-07-21.**

Module 7 is frozen based on successful validation in the
production-equivalent Hostinger/LiteSpeed environment. Cross-host
validation (e.g., Apache/Nginx with different PHP/MySQL versions) is
recommended before the first commercial GA release.

Module 7 completed full validation: automated checks (PHP syntax,
PHPUnit, PHPCS — `scripts/validate-module-7.sh` sections 0–6) and manual
runtime validation against a real WordPress + MySQL + WP-Cron
environment (sections 7–15, all 9 items PASS with direct evidence). Full
evidence trail: `docs/verification/2026-07-21-module-7-runtime-validation.md`.

Two genuine defects were found and fixed during runtime validation —
both reachable only through real, full-container execution, neither
catchable by the unit test suite's fake-DB harness:

1. `ActionRegistryInterface` was container-bound `bind()` instead of
   `singleton()` — every workflow action type was unusable at runtime
   despite passing all unit tests.
2. `QueueRepository::claimNextForWorker()` (Module 3, frozen) had no
   stale-claim recovery — a crashed worker orphaned its job forever.
   Fixed as an authorized post-freeze Storage change, unit-tested, and
   live-reverified on real orphan state; see
   `docs/verification/authorized-frozen-changes.txt`.

**Permanent release documentation** (kept as the audit trail template
for every future module): validation report, `authorized-frozen-changes.txt`,
`CHANGELOG.md`, `RELEASE_NOTES.md`.

## Module 8 — Milestone 3 (next)

Milestone 3 (Planner / Validator / Scheduler and the remaining
research → generation → validation → publish → post-process → events
pipeline, editorial approval workflow integration, publishing events,
and REST controllers) begins now that Milestone 2 is frozen, per
`MODULE_8_PUBLISHING_ENGINE_DESIGN.md`'s incremental delivery plan and
the project's standing audit-before-code discipline.

## Guiding principles (unchanged since Module 1)

Truth before speed. Quality before quantity. Security by design.
Enterprise architecture. Audit before code. No bundled sprints. No
modification of frozen modules without an explicit, documented,
narrowly-scoped exception.

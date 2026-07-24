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
| 8 | Publishing Engine | **Milestone 4 frozen — 2026-07-24** (Milestone 5 scope not yet defined) |

## Module 8 — Milestone 4 (AI-generation pipeline) freeze record

**Status: MILESTONE 4 FROZEN — 2026-07-24.** Module 8 as a whole remains
in progress; Milestone 5 (if any) has no defined scope yet anywhere in
this project's design docs or ADRs.

Milestone 4 adds the AI-generation pipeline ADR-0018 explicitly
deferred: `GenerateAction` (turns a completed research session's
`ResearchSummary` into a sanitized, persisted draft via `AIManager` +
a new `AiContentGenerator`), `ValidateContentAction` (merges the frozen
`DefaultEditorialPolicy` with a new second `EditorialPolicyInterface`
implementation, `ResearchEditorialPolicy`, checking citation count/
confidence/contradictions), and `PostProcessAction` (deterministic SEO
metadata into the previously-unused `ana_draft_seo` table from
Milestone 1). See ADR-0019 for the full trust-boundary design: where
`wp_kses_post()`/`esc_html()` sanitize AI-generated content and
deterministic citations respectively, and how `AIException`'s
retryability classification bridges into `WorkflowStepException` so
`WorkflowStepRetryExecutor` actually retries transient provider
failures.

Full local pipeline (`php -l`, PHPUnit — 557 tests/1 documented
incomplete, PHPCS clean on every new file) and two independent runtime
passes both passed: a local real-database harness (MariaDB +
WordPress 6.8.3 `wpdb`/`dbDelta` via the production boot path)
exercising the complete `GenerateAction → ValidateContentAction →
PostProcessAction` pipeline against a real `AIManager` (network call
faked, all orchestration real) and confirming the citation-escaping
trust boundary holds at runtime; and a live Hostinger smoke test on the
actual deployed artifact, also passing. One real defect was found and
fixed during the Hostinger pass — `wp eval-file` is incompatible with a
leading `declare(strict_types=1)` (fataled via PHP's `eval()`
semantics) — scoped entirely to the smoke-test script itself, not any
`src/` file. Evidence trail:
`docs/verification/2026-07-23-module-8-milestone-4-runtime-verification.md`.

Process improvement adopted this milestone: `./scripts/verify-runtime.sh full`
now runs every milestone checklist in order, stopping at the first
failure — the regression-suite entry point for the growing checklist
set as the project scales toward later modules.

## Module 8 — Milestone 3 (PublishingService / Validator / Scheduler) freeze record

**Status: MILESTONE 3 FROZEN — 2026-07-23.** Module 8 as a whole remains
in progress; Milestone 4 (the AI-generation pipeline) has not started.

Milestone 3 adds `PublisherInterface`/`PublishingService`
(publish/schedule/unpublish/archive), `EditorialPolicyInterface`/
`DefaultEditorialPolicy` (AI-disclosure + word-count checks), four new
Workflow actions (the first real use of the previously-unused
`ActionRegistryInterface` extension point), six new Publishing events,
`PublishingAbilityPolicy`, a REST controller (profile list/create plus
the four publish operations), and `PublishingHealthCheck`. See
ADR-0018 for the full scope reasoning, including why "Planner" (named
in the Milestone 2 freeze checklist with no further definition anywhere)
collapses into existing components rather than becoming a new
speculative class, and why the AI-generation pipeline is explicitly
deferred.

Full local pipeline (`php -l`, PHPUnit — 522 tests/895 assertions/1
documented incomplete, PHPCS) and two independent runtime passes both
passed with no defects found: a local real-database harness (MariaDB +
WordPress 6.8.3 `wpdb`/`dbDelta` via the production boot path) covering
all six required areas — PublishingService operations, REST endpoints,
Workflow actions, event dispatch, authorization policies, and health
check registration — with explicit, reproducible, assertion-backed
results; and a live Hostinger smoke test confirming the deployed
artifact runs fault-free on the real production stack. Evidence trail:
`docs/verification/2026-07-23-module-8-milestone-3-runtime-verification.md`.

Unlike Milestone 2's runtime pass (which found and fixed the D12
concurrency race), this pass found nothing to fix — ADR-0018's
architecture and scope decisions stand unchanged.

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

## Module 8 — Milestone 5 (next, scope undefined)

No further Module 8 milestone is named or scoped anywhere in
`MODULE_8_PUBLISHING_ENGINE_DESIGN.md`, ADR-0018, or ADR-0019 — unlike
the Milestone 3 → 4 handoff, where ADR-0018 explicitly named and
deferred the AI-generation pipeline. Per this project's standing
audit-before-code discipline, the next unit of work (whether a further
Module 8 milestone or a new module entirely) should be scoped from an
explicit source (a design doc, an owner instruction, or an updated
checklist) before any code is written, not assumed from precedent.

## Guiding principles (unchanged since Module 1)

Truth before speed. Quality before quantity. Security by design.
Enterprise architecture. Audit before code. No bundled sprints. No
modification of frozen modules without an explicit, documented,
narrowly-scoped exception.

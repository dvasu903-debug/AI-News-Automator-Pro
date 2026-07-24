# Release Notes

## 2.0.0-dev — Module 9 (SEO Engine) frozen

**Date:** 2026-07-24

### What's new

Module 9 adds the SEO Engine identified via a project-wide architecture
review as the correct next module (named three separate times across
existing project documents, not invented). `SeoServiceProvider` (ninth
in `ModuleManifest`) wires: `MetaTagBuilder` (constructs canonical URL,
Open Graph, Twitter Card, and schema.org `NewsArticle` JSON-LD tag data
from the frozen, previously-unused `ana_draft_seo` table),
`SeoProviderInterface`/`DefaultSeoProvider` (an extensibility seam for
future SEO providers, per an owner-requested design refinement),
`InternalLinkSuggester` (admin-only, deterministic, no AI call — ranks
published posts by shared research-entity count), `BreadcrumbGenerator`,
and `SeoHeadRenderer` — the module's one rendering/escaping boundary,
and the first module in this project's history to run on the public,
anonymous-visitor-facing `wp_head` hook. See `docs/adr/0020-*.md` for
the full trust-boundary design: HTML-attribute escaping, URL escaping,
and `JSON_HEX_TAG | JSON_HEX_AMP` JSON-LD escaping (chosen over the
initially-proposed `JSON_UNESCAPED_SLASHES`, which was corrected during
implementation), applied at every output site regardless of any
upstream sanitization already performed for a different context.

### Fixed

Two test/harness-infrastructure defects were found and fixed while
building this milestone's tests (not `src/`): `tests/bootstrap.php`'s
`wp_json_encode()` stub silently ignored its `$flags` parameter, and the
runtime harness's `get_permalink()` shim returned `false` for any post
without an explicitly pre-seeded permalink, unlike real WordPress. One
deployment/operational defect was found and fixed during the live
Hostinger pass: replacing an already-active plugin's directory with a
fresh `git clone` doesn't retrigger `register_activation_hook()`, so
pending migrations never ran until a genuine deactivate/activate cycle
forced the transition — documented as a permanent operational note in
`docs/DEPLOYMENT.md`, not an ADR (no architectural decision changed).

### Validated

Full local pipeline (`php -l`, PHPUnit — 600 tests, PHPCS clean on every
new file, including a new escaping-regression test class proving a
hostile string never survives unescaped in an HTML attribute, a URL, or
a JSON-LD-inside-`<script>` context) plus two independent runtime
passes: a local real-database harness (MariaDB + WordPress 6.8.3
`wpdb`/`dbDelta` via the production boot path) and a live Hostinger
smoke test on the actual deployed artifact
(`scripts/hostinger/module9-smoke-test.php`) — the first in this
project to verify public-facing rendered output over real anonymous
HTTP (a live `wp_remote_get()` fetch of the real post's permalink),
not just admin/REST/CLI-side behavior. All ten smoke-test assertions
passed, including the live-fetch check passing cleanly on the first
attempt. Full report:
`docs/verification/2026-07-24-module-9-seo-engine-runtime-verification.md`.

### Known, documented, non-blocking findings

- `InternalLinkSuggester` has no dedicated runtime-harness coverage
  (unit tests only) — admin-editor-only, reachable from no automated
  public/API path this milestone.
- No human-editable override path exists for `ana_draft_seo` fields —
  deliberate, deferred scope (ADR-0020 decision 8).
- `canonical_url` in `ana_draft_seo` remains `null` — deliberately never
  backfilled by this module; computed live via `get_permalink()`
  instead (ADR-0020 decision 3).

### Compatibility

No breaking changes to Modules 1–8. No changes to any frozen module
beyond the three designated append points every prior module has also
used (`ModuleManifest.php`'s provider list, `phpunit.xml.dist`'s
testsuite list, `verify-runtime.sh`'s `FULL_SEQUENCE`). `ana_draft_seo`
gains no new writer — Module 9 reads it only; `PostProcessAction`
(Publishing, Milestone 4) remains its sole writer.

## 2.0.0-dev — Module 8 Milestone 4 (AI-generation pipeline) frozen

**Date:** 2026-07-24

### What's new

Milestone 4 adds the AI-generation pipeline ADR-0018 explicitly
deferred, on top of Milestone 3's publish/schedule/unpublish/archive
operations: `GenerateAction` turns a completed research session's
`ResearchSummary` into a sanitized, persisted draft via a new
`AiContentGenerator` (backed by `AIManager`); `ValidateContentAction`
merges the frozen `DefaultEditorialPolicy` with a new second
`EditorialPolicyInterface` implementation, `ResearchEditorialPolicy`
(citation count, confidence, contradiction checks); and
`PostProcessAction` derives SEO metadata deterministically and
persists it via a new `DraftSeoRepository` into the previously-unused
`ana_draft_seo` table. See `docs/adr/0019-*.md` for the full
trust-boundary design — where AI-generated content is sanitized
(`wp_kses_post()`), how deterministic citations are escaped
(`esc_html()`) before being appended, and how a provider's
retryability classification is bridged into the Workflow engine's own
retry mechanism so a transient failure (rate limit, provider outage)
is actually retried instead of failing outright.

### Fixed

`scripts/hostinger/milestone4-smoke-test.php` fataled under `wp
eval-file` due to a `declare(strict_types=1)`/`eval()` incompatibility
in WP-CLI itself — found and fixed during the live Hostinger pass,
scoped entirely to that smoke-test script (no `src/` file affected).

### Validated

Full local pipeline (`php -l`, PHPUnit — 557 tests, 1 documented
incomplete — PHPCS clean on every new/changed file) plus two
independent runtime passes: a local real-database harness (MariaDB
10.11 + WordPress 6.8.3 `wpdb`/`dbDelta` via the production boot path)
exercising the complete three-action pipeline end-to-end against a
real `AIManager` (only the network call faked) — including an explicit,
executed proof that the citation-escaping trust boundary holds at
runtime (a deliberately markup-bearing citation appeared in the
persisted post only in its escaped form) — and a live Hostinger smoke
test on the actual deployed artifact, confirming plugin boot, action
resolution/registration, event dispatch, and `ana_draft_seo`
persistence against real production MySQL. Full report:
`docs/verification/2026-07-23-module-8-milestone-4-runtime-verification.md`.

Process improvement adopted this milestone: `./scripts/verify-runtime.sh full`
runs every milestone checklist in order, stopping at the first
failure — the regression-suite entry point for the growing checklist
set.

### Known, documented, non-blocking findings

- No real AI provider call was exercised anywhere (by design — a live
  call is optional and cost-gated per the site owner's instruction).
  The real orchestration layer around that call was fully exercised on
  both environments; only the actual HTTP request/response to a real
  vendor was not.
- `EditorialPolicyInterface`'s citation-count/confidence checks are now
  implemented but only apply to drafts with a linked research session —
  a manually-created draft still passes trivially (no research to
  validate against), consistent with ADR-0018's original scope note.
- `PostProcessAction`'s `canonical_url` is left `null` this milestone
  (no permalink source integrated yet) — a documented, deliberate scope
  limit, not a defect.

### Compatibility

No breaking changes to Modules 1–7 or Module 8 Milestones 1–3. No
changes to any frozen module — `EditorialPolicyInterface`,
`DefaultEditorialPolicy`, and `EditorialPolicyResult` are all untouched;
`ResearchEditorialPolicy` is bound as its own concrete-class container
entry alongside the existing `EditorialPolicyInterface` binding, not a
replacement of it.

## 2.0.0-dev — Module 8 Milestone 3 (PublishingService / Validator / Scheduler) frozen

**Date:** 2026-07-23

### What's new

Milestone 3 adds the publishing operations themselves on top of
Milestone 2's profile management: `PublisherInterface`/
`PublishingService` (`publish`, `schedule`, `unpublish`, `archive`),
`EditorialPolicyInterface`/`DefaultEditorialPolicy` (AI-generation
disclosure and word-count policy checks), four new Workflow actions
registered into the previously-unused `ActionRegistryInterface`
extension point, six new Publishing domain events, `PublishingAbilityPolicy`,
a REST controller, and a health check. See `docs/adr/0018-*.md` for how
the milestone's four named components (PublishingService / Planner /
Validator / Scheduler — the last two terms had no prior definition
anywhere in the project) were interpreted and scoped, and why the
AI-generation pipeline (draft content generation, AI-backed validation)
is explicitly deferred to a future milestone.

### Fixed

Nothing — this milestone's runtime verification found no defects (see
"Validated" below), unlike Milestone 2's pass which found and fixed a
concurrency race in `markDefault()`.

### Validated

Full local pipeline (`php -l`, PHPUnit — 522 tests/895 assertions, 1
documented incomplete — PHPCS) plus two independent runtime passes: a
local real-database harness (MariaDB 10.11 + WordPress 6.8.3
`wpdb`/`dbDelta` via the production boot path) with explicit,
reproducible, assertion-backed checks across all six required areas —
PublishingService operations (publish/schedule/unpublish/archive, each
confirmed by database state and dispatched event), REST endpoint
registration and invocation, Workflow action registration and end-to-end
execution, event dispatch, authorization policy decisions via the real
`CapabilityGate`/`PolicyEngine`, and health check registration — and a
live Hostinger smoke test confirming the deployed artifact (`git pull`
to the frozen commit, `composer install --no-dev`) runs fault-free on
the real production stack for its core publish/archive/unpublish
operations, including the AI-generated-draft `approve()` branch. Full
report: `docs/verification/2026-07-23-module-8-milestone-3-runtime-verification.md`.

### Known, documented, non-blocking findings

- `EditorialPolicyInterface`'s citation-count and Research-confidence
  checks are not yet implemented — they require
  `Research\DTO\ResearchSummary` integration, part of the deferred
  AI-generation milestone.
- The Hostinger smoke test's scope was narrower than the local harness's
  (core PublishingService operations only, not REST/Actions/Events/
  Authorization/HealthCheck) — accepted, since the local harness's
  coverage of those areas is real and assertion-backed against an
  equivalent real-database stack; see the runtime report's "Scope of the
  Hostinger pass" section for the precise boundary.
- REST controllers and health checks have no dedicated unit tests
  anywhere in this codebase (established precedent across every
  module, not specific to this milestone) — covered instead by the
  runtime passes above.

### Compatibility

No breaking changes to Modules 1–7 or Module 8 Milestones 1–2. No new
migrations; no changes to any frozen module (`PublishingAbilityPolicy`
maps to existing `Capabilities` constants rather than adding new ones).

## 2.0.0-dev — Module 8 Milestone 2 (Publishing Profiles) frozen

**Date:** 2026-07-23

### What's new

Milestone 2 adds Publishing Profile management on top of Module 8's
Milestone 1 `DraftRepository` foundation: CRUD for publishing profiles
(`slug`, `name`, `vertical`, `workflow_key`, `approval_mode`, JSON
`config`), single-writer default-profile promotion/demotion via
`is_default`, uniqueness enforcement on slug and name, and structural
(non-enum) validation of `approval_mode` — no fixed value list is
imposed unless one is separately approved.

### Fixed

- **Concurrent default-profile switching could produce two defaults** —
  `PublishingProfileRepository::markDefault()`'s demote step read the
  current default via an unlocked query and demoted only that specific
  row; two near-simultaneous calls could each act on the same stale
  snapshot. Found via a real parallel-process test against a live
  database (runtime checklist item D12), invisible to the synchronous
  unit test suite. Now demotes via a single blanket exact-match
  `UPDATE ... WHERE is_default = 1`, which locks every currently-default
  row and serializes concurrent callers. Re-verified clean across 300+
  interleaved switches.
- Raw `json_encode()` calls (the only ones in `src/`) switched to
  `wp_json_encode()` for consistency with every other module.
- CI's PHPCS step no longer hard-fails the build on pre-existing
  baseline findings in frozen modules, matching this project's own
  documented PHPCS policy; it still fails the build if PHPCS itself
  fails to execute.

### Validated

Full local pipeline (syntax, PHPUnit — 490 tests/826 assertions, 1
documented incomplete — PHPCS) plus runtime verification against a real
database: first against a locally-provisioned MariaDB + WordPress 6.8.3
`wpdb`/`dbDelta` running the actual production boot path (container
identity probe, migration idempotency, full CRUD, utf8mb4/emoji
round-trip, default-switch concurrency, transaction rollback,
`requireDefault()` failure path, all four policy checks), then a live
smoke test on the production Hostinger deployment (plugin activation,
migration execution, `is_default` column, default create/switch,
`requireDefault()` behavior, admin dashboard loads clean). Full
reports: `docs/verification/2026-07-23-module-8-milestone-2-local-verification.md`
and `docs/verification/2026-07-23-module-8-milestone-2-runtime-verification.md`.

### Known, documented, non-blocking findings

- `existsWithSlug()`/`existsWithName()`'s `$excludeId` path can't be
  exercised against the unit test suite's `FakeWpdb` double (it doesn't
  model the `!=` SQL operator); this is closed by real-database runtime
  verification instead, which exercised all four directions
  successfully.
- Slug uniqueness has a DB-level UNIQUE index; name uniqueness is
  service-level only (accepted for this milestone — revisit only if a
  real collision is observed).

### Compatibility

No breaking changes to Modules 1–7 or Module 8 Milestone 1. Additive-only
migration; the three Milestone 1 Publishing migrations are unmodified.

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

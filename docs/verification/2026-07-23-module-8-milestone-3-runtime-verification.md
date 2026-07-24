# Module 8 (Publishing Engine) — Milestone 3 Runtime Verification & Freeze Report

Date: 2026-07-23
Baseline: `d0f6fdc` (Milestone 2 frozen) + `6035b02` (Milestone 3
implementation: PublishingService, EditorialPolicy, Actions, Events,
Authorization, REST, Health — see ADR-0018).

## Runtime environment

Two independent runtime passes, same as the Milestone 2 process:

| Pass | Environment |
|---|---|
| Local harness | MariaDB 10.11.14 (InnoDB, utf8mb4), WordPress 6.8.3 `class-wpdb.php` (verbatim), WordPress 6.8.3 `dbDelta()` (verbatim), PHP 8.4.19 CLI, the real production entry point (`PluginFactory::create()->boot()` on `plugins_loaded`, all 8 module providers) |
| Hostinger (tfgadgets.com) | Live production LiteSpeed/PHP-FPM stack, real MySQL, real WordPress install, plugin deployed via `git pull` to commit `6035b02` + `composer install --no-dev --optimize-autoloader` |

## Local harness results — all six required areas, with explicit assertions

Driver: `milestone3-checklist.php`, run twice from a freshly-dropped
database (reproducibility check). Every item below is an individual
`assert`-backed PASS, not a narrative claim.

**1. PublishingService operations** — `publish()` on a manually-created
draft (direct `wp_update_post`, verified `post_status` transitioned to
`publish`), `schedule()` on a draft (verified `post_status=future` and
`post_date` set to the exact requested timestamp), `unpublish()`
(reverted to `draft`), `archive()` (WordPress-native `private` status)
— all four via the real container-wired `PublisherInterface`, all four
PASS.

**2. EditorialPolicyInterface (Validator)** — real container-resolved
`DefaultEditorialPolicy` correctly detected a word-count violation
against a real, database-persisted `PublishingProfile`'s config. PASS.

**3. Workflow actions** — all four action types
(`publishing.publish_draft`, `publishing.schedule_draft`,
`publishing.unpublish`, `publishing.archive`) confirmed registered in
the real, container-resolved `ActionRegistryInterface` (populated by
`PublishingServiceProvider::boot()`, the first real consumer of that
extension point). `PublishDraftAction` executed end-to-end through a
real `WorkflowRunContext`, successfully publishing a real post; a second
run with a nonexistent profile id failed cleanly (`ActionResult::failure`,
no fatal). PASS.

**4. Event dispatch** — every `PublishingService` operation's
corresponding event (`ArticlePublishedEvent`, `ArticleScheduledEvent`,
`ArticleUnpublishedEvent`, `ArticleArchivedEvent`) confirmed dispatched
via a real `EventDispatcherInterface::addListener()` capture, for each
of the four operations. PASS.

**5. Authorization policies** — real, container-resolved
`CapabilityGateInterface` (the actual `PolicyEngine`-backed
implementation, not a fake) correctly allowed a user with
`Capabilities::RUN_PIPELINE` to perform `PublishingAbilityPolicy::PUBLISH`,
and correctly denied a user without it — end-to-end through
`PublishingAbilityPolicy`'s real `decide()` mapping, confirmed via both
an explicit-context call and the current-user resolution path. PASS.

**6. REST endpoints** — all six `/publishing/*` routes confirmed present
in the real `register_rest_route()` call log after `rest_api_init` fired
through `PublishingController::registerRoutes()`. Two route callbacks
invoked directly against the real container (`listProfiles()` returned
real persisted profiles; `publish()` executed the full REST→Service
path successfully). PASS.

**Health check registration** — `PublishingHealthCheck` resolved via the
real container and its `run()` returned both expected results, correctly
reporting `Ok` against real, persisted profile data. PASS.

All checks passed on the first run and were confirmed reproducible on a
second, independent fresh-database run. **No defects were found or
fixed in this pass** — Milestone 3 differs from Milestone 2 in this
respect (Milestone 2's runtime pass found and fixed the D12 concurrency
race; this pass found nothing to fix).

## Hostinger smoke test

Deployment confirmed: `git log -1` on the server showed `HEAD` at
`6035b02` matching `origin` with zero commit difference; `composer
install --no-dev --optimize-autoloader` regenerated the autoloader
cleanly; all Milestone 3 files (`PublishingService.php`, all four
`Actions/`, `PublishingController.php`, `PublishingAbilityPolicy.php`,
`PublishingHealthCheck.php`) confirmed present on disk.

Live execution: two real posts were created via the real
`DraftRepositoryInterface` (`M3 Smoke Manual`, no AI-generation meta;
`M3 Smoke AI`, with `_ana_generated=1` meta). Against these, the real,
production-booted `PublisherInterface` executed `publish()` on **both**
posts (exercising both `PublishingService::publish()` branches — the
manual `wp_update_post` path and the AI-generated
`ArticleRepositoryInterface::approve()` path, the latter of which the
local harness's own script happened not to separately exercise via
`publish()`), then `archive()` on the manual post and `unpublish()` on
the AI post. All four calls completed with no fatal error and no
WordPress critical-error page.

**Scope of the Hostinger pass, stated precisely:** this smoke test
confirmed the deployed code runs without fatal error on the real
production stack for `PublishingService`'s publish/archive/unpublish
operations (not `schedule()`, which was only exercised in the local
harness). It did not include explicit automated assertions on the
resulting `post_status` values or dispatched events for that run (the
script printed progress messages, not assertions), and it did not
separately re-exercise REST endpoints, Workflow actions, authorization
policies, or health check registration against Hostinger specifically
— those six areas received their explicit, assertion-backed proof from
the local real-database harness described above. The Hostinger pass's
value is complementary and real: it proves the actual deployed
artifact, under the actual production PHP/web-server/MySQL stack, boots
and executes these core operations without failure — the one thing no
local harness can fully stand in for.

Temporary test posts (`132`, `133`) were deleted after the smoke test;
`wp post list --post__in=132,133 --format=count` confirmed `0` remaining.

## Defects found and fixed

None. Both the local harness (comprehensive, assertion-backed, all six
required areas) and the Hostinger smoke test (real production stack,
core operations) passed without exposing any defect in this pass.

## Remaining known limitations (carried from ADR-0018, unchanged)

- AI-generation pipeline (`GenerateAction`, AI-backed content
  validation, `PostProcessAction`) is explicitly out of scope for
  Milestone 3 — deferred to a future milestone.
- `EditorialPolicyInterface`'s citation-count and Research-confidence
  checks are not yet implemented (require `Research\DTO\ResearchSummary`
  integration, part of the deferred work above).
- REST controllers and health checks have no dedicated unit tests in
  this codebase for any module (established precedent, not a
  Milestone-3-specific gap) — covered instead by the runtime passes
  above.
- The Hostinger pass did not independently re-verify REST/Actions/
  Events/Authorization/HealthCheck the way the local harness did (see
  "Scope of the Hostinger pass" above) — accepted, since the local
  harness's coverage of those areas is real and assertion-backed against
  an equivalent real-database stack.

## Recommendation

**Freeze is recommended.** All six required verification areas passed
with explicit, reproducible, assertion-backed proof against a real
database and the real production boot path; the actual deployed
artifact was additionally confirmed fault-free on the live Hostinger
production stack for its core state-transition operations. No defects
were found, so none needed fixing — ADR-0018's architecture and scope
decisions stand unchanged.

# ADR-0018: Publishing Milestone 3 Scope and "Planner" Interpretation

**Status:** Accepted · **Module:** 8

## Context

The Milestone 2 freeze checklist's exit criterion (item E15, inherited from
an earlier delivery package) named "Milestone 3 (PublishingService /
Planner / Validator / Scheduler)" as the next unit of work, with no further
elaboration anywhere. A repository-wide search confirmed **zero prior art**
for "Planner" as a concept: it appears in exactly that one checklist line
and nowhere else — no class, no design-doc section, no test. The
`MODULE_8_PUBLISHING_ENGINE_DESIGN.md` design doc (itself still headed
"DRAFT — pending approval") describes `PublisherInterface`
(publish/schedule/unpublish/archive) and `EditorialPolicyInterface`
(citation/confidence/disclosure/word-count checks) in enough detail to
build against, but never uses the word "Planner" and has no "Scheduler"
section beyond "reuse WordPress's native `post_date`/`status=future`."

Building an undefined "Planner" subsystem with no real caller would mean
inventing scope rather than discovering it — exactly what this project's
"audit before code" discipline and the standing instruction to avoid
designing for hypothetical future requirements both warn against.

## Decision

**Milestone 3 ships one cohesive service, not four separate subsystems.**
`Publishing\Services\PublishingService` implements `PublisherInterface`
(`publish`, `schedule`, `unpublish`, `archive`). The four named terms map
onto facets of this one component rather than four standalone classes:

- **PublishingService** — the service itself.
- **Scheduler** — the `schedule()` method, using WordPress's native
  `post_status=future` + `post_date` mechanism (per the design doc's own
  §3.4 scope discipline: reuse WordPress's mature systems, don't duplicate
  them). Confirmed during planning that `Workflow\Scheduling\WorkflowScheduler`
  is a different domain concern entirely (a WP-Cron tick that triggers
  *workflow runs* by `workflow_key`, hard-wired, no arbitrary-timestamp
  API) and is not the right reuse target here.
- **Validator** — `EditorialPolicyInterface` /
  `DefaultEditorialPolicy`, consulted by `PublishingService` before a
  publish/schedule proceeds. Scoped to what Milestone 3 can actually
  validate without inventing an unbuilt integration: AI-generation
  disclosure (via `DraftRepositoryInterface::isGenerated()`, already
  built) and profile-configured word-count bounds. Citation-count and
  Research-confidence checks from the design doc's §3.2 sketch require
  `Research\DTO\ResearchSummary`, which nothing in Publishing consumes
  yet (that's the deferred AI-generation pipeline — see below) — this
  gap is stated here rather than silently worked around, and the
  interface is shaped so adding those checks later doesn't require a
  breaking change.
- **Planner — dropped as a separate concept, on a closer read of
  Decision 3.** An earlier draft of this ADR folded an `approval_mode`
  runtime check into the new Actions (skip straight to publish if
  `auto`, otherwise require an `approval_gate` step first). That would
  have **duplicated approval logic inside Publishing** — exactly what
  Decision 3 rules out: "Publishing actions pause via the existing
  `approval_gate` mechanism... no approval logic is duplicated inside
  Publishing." Per design doc §6, different `approval_mode` values are
  different workflow-DEFINITION *shapes* (whether an `approval_gate`
  step precedes `PublishDraftAction` in the stored step sequence) — a
  decision made when a profile's workflow definition is authored/
  provisioned, not something `PublishDraftAction` re-decides on every
  run. Provisioning a workflow definition from a profile's
  `approval_mode` is exactly the "Workflow-definition generator" scope
  this ADR already defers (see below) — so there is nothing left for a
  "Planner" to do at runtime in this milestone. `PublishDraftAction`
  and `ScheduleDraftAction` simply run when the `WorkflowRunner` reaches
  them; the only pre-publish decision logic they contain is the
  **Validator** (`EditorialPolicyInterface::evaluate()`) check —
  content/policy quality, not workflow shape.

**Explicitly deferred to a future milestone** (not built now): AI-backed
draft generation (`GenerateAction` consuming `AIManager` +
`Research\DTO\ResearchSummary`), AI-backed content validation, and
`PostProcessAction`. These require their own prompt-template design and
cost/quality tradeoffs — a materially different, larger design surface
than schedule/validate/publish state transitions, and out of scope for
"PublishingService / Planner / Validator / Scheduler" as named. Building
them now would violate the instruction to keep changes modular and avoid
unrelated scope expansion.

**Authorization reuses the established extension pattern instead of the
design doc's speculative one.** The design doc (§9) suggested adding new
raw WordPress capability constants (`ana_publish_content`,
`ana_manage_profiles`) directly to `Security\Authorization\Capabilities`
(a frozen Module 2 class). That is *not* what Workflow or Research
actually did once built: both instead defined their own ability-name
constants in a module-owned `XAbilityPolicy implements PolicyInterface`,
mapping each ability to an *existing* `Capabilities` constant
(`WorkflowAbilityPolicy::MANAGE` → `Capabilities::RUN_PIPELINE`, etc.),
tagged `security.policies` — touching zero lines of the frozen
`Capabilities` class. `PublishingAbilityPolicy` follows that real,
established precedent: `PublishingAbilityPolicy::PUBLISH` maps to
`Capabilities::RUN_PIPELINE`, `PublishingAbilityPolicy::MANAGE_PROFILES`
maps to `Capabilities::RUN_PIPELINE` also (profile management is
pipeline configuration, not a distinct capability domain), and
`PublishingAbilityPolicy::VIEW` maps to `Capabilities::VIEW_ANALYTICS`.
`Capabilities.php` is not modified.

**`archive()` uses WordPress's native `private` post status.** No
"archived" status exists in WordPress core or anywhere in this codebase.
`private` (visible to users with `read_private_posts`, excluded from
public queries/feeds) is the closest native fit and requires no custom
post-status registration — consistent with the design doc's "reuse
WordPress natively" discipline for scheduling/revisions/previews.

**`publish()` reuses `Storage\Contracts\ArticleRepositoryInterface::approve()`
for AI-generated drafts, and falls back to a direct `wp_update_post()`
for manually-created ones.** `approve()` internally no-ops (returns
`false` without transitioning status) when `isGenerated($postId)` is
false — it is specifically the AI-draft-review-approval path, not a
general "publish any post" primitive. `PublishingService::publish()`
checks `isGenerated()` first: when true, it delegates to `approve()`,
reusing that frozen, tested Module 3 transition and its
`ArticleApprovedEvent` dispatch, per the explicit instruction to "reuse
existing service contracts where appropriate"; when false (a
manually-created draft with no AI provenance), it performs the
`post_status=publish` transition directly via `wp_update_post()`, since
`approve()` cannot help here. `schedule()`/`unpublish()`/`archive()`
have no existing repository method to reuse at all (the interface's
scope is deliberately narrow — creation + review-queue reads only) and
always use `wp_update_post()` directly, mirroring the precedent
`Publishing\Repositories\DraftRepository` already set (its own
`update()`/`delete()` call WordPress core functions directly rather
than routing through `ArticleRepositoryInterface`).

## Consequences

- Milestone 3's surface is: `PublisherInterface` + `PublishingService`,
  `EditorialPolicyInterface` + `DefaultEditorialPolicy`, four new
  `ActionInterface` implementations (`PublishDraftAction`,
  `ScheduleDraftAction`, `UnpublishAction`, `ArchiveAction`) registered
  into the previously-unused `ActionRegistryInterface` extension point,
  new Publishing events, `PublishingAbilityPolicy`, a REST controller
  covering publish/schedule/unpublish/archive plus the profile
  list/create endpoints Milestone 2 shipped without, and
  `PublishingHealthCheck`. No new "Planner" class, no new Publishing
  capability constants, no changes to any frozen module.
- The citation-count/confidence-score gap in `EditorialPolicyInterface`
  is a known, documented limitation until the deferred AI-generation
  milestone lands and Publishing actually consumes `ResearchSummary`.
- A future milestone introducing `ResearchSummary`-based validation
  extends `DefaultEditorialPolicy` (or adds a second policy
  implementation) rather than requiring a redesign of the interface,
  since `evaluate()` already returns a violations list open to more
  checks.

## Alternatives Considered

- **Build a standalone `PublishingPlanner` class per the checklist's
  literal wording**, or fold an `approval_mode` runtime check into the
  new Actions. Rejected: no requirement or caller for a separate class
  exists at this scope, and a runtime approval-mode check would
  duplicate logic Decision 3 explicitly assigns to workflow-definition
  shape, not action code.
- **Follow the design doc's suggestion to add new capability constants
  to `Capabilities.php`.** Rejected: contradicts the actual established
  precedent set by both modules that have since been built
  (Workflow, Research), and would touch a frozen Module 2 file
  unnecessarily.
- **Build the full remaining Module 8 design (including AI-generation
  actions) in this milestone.** Rejected: a materially larger, separate
  design surface (prompt design, generation cost/quality) than
  publish/schedule/validate state transitions; keeping milestones narrow
  is this project's established discipline (Milestone 1 was
  migrations-only, Milestone 2 was profile-CRUD-only).

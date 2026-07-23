# Module 8 — Publishing Engine — Design (DRAFT — pending approval)

**Status: draft, not yet approved.** Per this project's standing
discipline (audit → design → approval → build), implementation does not
begin until the open decisions below are resolved and this document is
internally consistent. Numbered decisions below follow the same pattern
as Module 7's approved Decisions 1–4 — each needs an explicit yes/no.

**Framing, restated from the brief:** not an autoblogging engine. A
professional publishing engine for trustworthy, AI-assisted publishing.
Truth before speed. Quality before quantity. Human value before
automation. White-hat SEO only.

---

## 1. Relationship to existing modules (read this first)

Module 8 is the first module built *on top of* a completed pipeline
rather than beside one. It must not modify Modules 1–7, must not touch
the DI container, migration framework, or event dispatcher, and should
reuse existing contracts wherever the shape genuinely fits — not force a
fit where it doesn't.

**What already exists and Module 8 should build on directly:**

- **`Storage\Contracts\ArticleRepositoryInterface`** (frozen, Module 3) —
  already wraps `WP_Post` + plugin postmeta for AI-generated articles,
  deliberately *without* a parallel `ana_articles` table (per the
  module's own README: WordPress's native post storage is the system of
  record for content). This is very likely the right foundation for
  draft persistence — see Decision 1.
- **`Workflow\Contracts\ActionInterface`** (frozen, Module 7) — the
  established extension point for pluggable pipeline steps. Publishing
  actions (`ResearchAction`, `GenerateAction`, `ValidateAction`,
  `PublishAction`, ...) register into `ActionRegistryInterface` exactly
  like Module 7's own five actions, and `WorkflowRunner` orchestrates
  them with zero Module 8-specific code inside Module 7. This is the
  cleanest read of "publish pipeline integration with the completed
  Workflow Engine" from the brief.
- **`Workflow\Runner\WorkflowRunner`**'s public surface —
  `run(workflowKey, triggeredBy, userId?, pinnedVersion?)`,
  `approve(runId, stepKey, userId, approved, reason?)`,
  `resumeFromQueueJob(...)` — is the entire integration surface Module 8
  needs for triggering and progressing a publishing run. No new
  orchestration primitives should be built in Module 8; if something
  feels missing, that's a signal to revisit this design, not to
  duplicate Workflow's job.
- **`Storage\Contracts\WorkflowRepositoryInterface` / `ana_workflows`**
  — legacy, superseded by Module 7's own versioned
  `WorkflowDefinitionRepositoryInterface` / `ana_workflow_definitions`.
  Still live only for the existing export/import feature. **Module 8
  must not use this for pipeline definitions** — use Module 7's real
  system.

---

## 2. Architecture

```
Research (Module 6, frozen)
    ↓  ResearchSummary DTO — the authoritative input contract
Publishing Engine (Module 8)
    ↓
  [Draft]  →  [AI Generation]  →  [Validation]  →  [Editorial Approval]
    →  [Publish]  →  [Post-processing]  →  [Events]
```

Each bracketed stage is one or more `ActionInterface` implementations,
composed via a `WorkflowDefinitionVersion` — i.e. a *Publishing Profile*
(§5) is, concretely, a stored Workflow definition whose steps are drawn
from Module 8's action set. Manual/direct operations (create a draft by
hand, edit, delete, unpublish, archive) are plain CRUD through
`PublishingService` and do **not** need to go through the workflow
engine — only the automated pipeline does.

### Folder structure

```
src/Publishing/
├── Contracts/
│   ├── DraftRepositoryInterface.php
│   ├── PublishingProfileRepositoryInterface.php
│   ├── PublisherInterface.php
│   └── PublishingApprovalPolicyInterface.php
├── Entities/
│   ├── Draft.php
│   ├── DraftStatus.php
│   ├── PublishingProfile.php
│   └── ApprovalMode.php
├── DTO/
│   ├── DraftInput.php
│   ├── PublishResult.php
│   ├── SeoMeta.php
│   └── FeaturedImageRef.php
├── Actions/                    (ActionInterface implementations)
│   ├── ValidateContentAction.php
│   ├── PublishDraftAction.php
│   ├── SchedulePublishAction.php
│   └── PostProcessAction.php
├── Services/
│   ├── DraftService.php        (create/update/delete — direct CRUD)
│   ├── PublishingService.php   (publish/schedule/unpublish/archive)
│   └── PublishingProfileRegistry.php
├── Repositories/
│   ├── DraftRepository.php     (wraps ArticleRepositoryInterface)
│   └── PublishingProfileRepository.php
├── Events/
│   ├── PublishingStartedEvent.php
│   ├── DraftCreatedEvent.php
│   ├── DraftUpdatedEvent.php
│   ├── ArticlePublishedEvent.php
│   ├── ArticleScheduledEvent.php
│   ├── PublishingFailedEvent.php
│   ├── PublishingCancelledEvent.php
│   └── PublishingCompletedEvent.php
├── Api/
│   └── PublishingController.php
├── Storage/
│   ├── PublishingMigrationManifest.php
│   └── Migrations/
├── Authorization/
│   └── PublishingAbilityPolicy.php
├── Health/
│   └── PublishingHealthCheck.php
└── PublishingServiceProvider.php
```

Mirrors Module 7's own structure exactly — same conventions, same
predictability for whoever reads this next.

---

## 3. Storage requirements

**Decision 1 (needs sign-off): drafts live on `wp_posts`, not a new
table.** Consistent with `ArticleRepositoryInterface`'s existing design
and WordPress's own content model (revisions, autosave, `wp_postmeta`,
taxonomy, `WP_Query` — all free). A parallel `ana_drafts` table would
duplicate WordPress's own machinery for no real benefit and would fight
the platform. If approved, `DraftRepository` wraps
`ArticleRepositoryInterface` (extending it if new methods are genuinely
needed) rather than introducing a new persistence layer.

**New tables Module 8 does need** (nothing WordPress already models):

```sql
-- ana_publishing_profiles: reusable pipeline configurations
id, name, slug, vertical, workflow_key (FK by convention → Module 7's
  workflow_key, not a DB foreign key — Storage's own no-FK ADR-0004),
approval_mode, config (LONGTEXT JSON), enabled, created_at, updated_at

-- ana_publishing_runs: one row per publish attempt, joins a Draft to
-- the Workflow run that's driving it
id, post_id, profile_id, workflow_run_id, status, created_at, completed_at

-- ana_draft_seo: SEO metadata not naturally covered by wp_postmeta's
-- flat key/value shape (kept structured for the future SEO module)
id, post_id, meta_title, meta_description, focus_keyword,
  canonical_url, robots_directives, created_at, updated_at
```

Migrations reuse `AbstractMigration`/`SchemaBuilder` exactly as every
prior module has — no framework changes.

---

## 4. Interfaces (core contracts)

```php
interface DraftRepositoryInterface
{
    public function create(DraftInput $input): int; // post ID
    public function update(int $postId, DraftInput $input): void;
    public function delete(int $postId): void;
    public function find(int $postId): ?Draft;
    public function findBySourceUrl(string $url): ?Draft; // dedup, reuses ArticleRepository's existing method
}

interface PublisherInterface
{
    public function publish(int $postId): PublishResult;
    public function schedule(int $postId, \DateTimeImmutable $at): PublishResult;
    public function unpublish(int $postId): PublishResult;
    public function archive(int $postId): PublishResult;
}

interface PublishingProfileRepositoryInterface
{
    public function find(string $slug): ?PublishingProfile;
    public function all(): array;
    public function save(PublishingProfile $profile): void;
}
```

`ActionInterface` implementations (`ValidateContentAction`,
`PublishDraftAction`, etc.) depend on these services via constructor
injection, exactly like Module 7's own actions depend on
`WorkflowRunContext` + their own narrow service dependencies — no
special-casing.

---

## 5. Publishing Profiles

A profile is a named, reusable configuration: which pipeline (Workflow
definition) to run, which `ApprovalMode` applies, and profile-specific
config (e.g. word-count targets, tone, required source count for "AI
News", template sections for "Buying Guide"). The five example profiles
from the brief (AI News, Product Review, Evergreen Article, Tutorial,
Buying Guide) are **data, not code** — rows in
`ana_publishing_profiles` referencing a Workflow definition version each
— not five hardcoded classes. New profiles should be addable without a
deploy.

## 6. Editorial approval

Maps directly onto Module 7's existing `approval_gate` action and REST
approval endpoint — already built, already validated (Item 12). No new
approval machinery needed:

- **Auto publish** — profile's workflow has no `approval_gate` step.
- **Manual approval** — one `approval_gate` step before `PublishDraftAction`.
- **Scheduled approval** — an approval that, once granted, triggers
  `SchedulePublishAction` instead of immediate `PublishDraftAction`.
- **Multi-stage approval** — multiple `approval_gate` steps in sequence,
  already fully supported by the engine as-is.

---

## 7. REST endpoints

Following `AbstractRestController`'s established pattern exactly
(namespace `ai-news-automator/v1`, `RestSecurityMiddleware` ability
checks, rate limiting):

```
POST   /publishing/drafts                        create a draft
PATCH  /publishing/drafts/{id}                    update
DELETE /publishing/drafts/{id}                    delete
POST   /publishing/drafts/{id}/publish            publish now
POST   /publishing/drafts/{id}/schedule           schedule
POST   /publishing/drafts/{id}/unpublish          unpublish
POST   /publishing/drafts/{id}/archive            archive
GET    /publishing/profiles                       list profiles
POST   /publishing/profiles                       create/update a profile
POST   /publishing/runs/{workflow_run_id}/trigger run the full pipeline for a draft under a profile
```

Approval decisions reuse Module 7's existing
`POST /workflow/runs/{id}/approvals/{step_key}` — no duplicate route.

---

## 8. Dependency graph

```
Publishing (8)
  ├── depends on → Workflow (7): ActionInterface, ActionRegistryInterface, WorkflowRunner
  ├── depends on → Research (6): ResearchSummary DTO (pipeline input)
  ├── depends on → AI Provider Engine (4): AIManager (generation actions call it)
  ├── depends on → Storage (3): ArticleRepositoryInterface, migration framework, event dispatcher
  ├── depends on → Security (2): RestSecurityMiddleware, CapabilityGate, audit logging
  └── depends on → Core (1): Container, EventDispatcher, RestApiRegistry
```
Strictly downstream — nothing in Modules 1–7 depends on Module 8. This
is what "does not modify Modules 1–7" looks like structurally, not just
as a rule.

---

## 9. Security considerations

- New capabilities needed, following `Capabilities.php`'s existing
  pattern: `ana_publish_content`, `ana_manage_profiles` — installed via
  the same `CapabilityInstaller` mechanism already proven in Item 12's
  investigation (and now with the documented object-cache caveat in
  mind for this host).
- Draft content is user-supplied/AI-generated text destined for public
  `wp_posts` — output escaping matters far more here than it did in
  Module 7's internal exception messages. Every rendered field goes
  through WordPress's own escaping functions at the point of output,
  not at storage time.
- SEO metadata fields are prime injection targets (meta descriptions,
  canonical URLs) — validate/sanitize via `Security\Request\InputValidator`,
  reusing rather than reinventing.

## 10. Failure handling & rollback

`PublishAction` should be treated as **not reversible** by default
(same `RollbackableActionInterface` opt-in model Module 7 already has —
`NotificationAction` is the precedent) once a post is genuinely live;
rolling back "publish" doesn't cleanly mean "delete the live post."
Pre-publish steps (draft creation, validation) are safely reversible.
`PublishingFailedEvent`/`PublishingCancelledEvent` carry enough context
(post ID, profile, failed step) for operators to act manually on a
failed publish — mirroring how Module 7 surfaces failures via
`error` on the run row rather than trying to auto-recover destructively.

## 11. Future extensibility

- SEO module (Module 9?) can own `ana_draft_seo` more fully without
  Module 8 needing to change — table already structured for it.
- Multi-vertical support (beyond "news") is already load-bearing in the
  schema (`vertical` column on profiles) rather than retrofitted later.
- Social sharing / analytics (mentioned in the plugin's own top-level
  description) are natural post-processing steps or entirely separate
  future modules — `PostProcessAction` and `PublishingCompletedEvent`
  are the intended extension seams.

---

## Approved decisions

**Decision 1 — `DraftRepositoryInterface` wraps `ArticleRepositoryInterface`, does not extend it.** Approved. Composition, not
inheritance: keeps the frozen Module 3 contract untouched, separates
draft/editorial concerns from generic article persistence, and doesn't
force every future `ArticleRepositoryInterface` implementation to
understand workflow concepts. Also makes future content types (pages,
products, CPTs) easier to add without touching this layer.

**Decision 2 — Publishing is a set of Module 7 actions; no second
orchestration layer.** Approved. `WorkflowRunner` remains the single
orchestrator. Publishing actions: Create Draft, Update Draft, Schedule
Publish, Publish, Unpublish, Archive, Update Metadata, Notify Editor —
each a thin `ActionInterface` implementation delegating to
`PublishingService`/`DraftService`, exactly like Module 7's own actions
delegate to their narrow dependencies. Module 8 does not become "another
workflow system" — its job is to provide the publishing capabilities
Module 7 orchestrates, nothing more.

**Decision 3 — Editorial approval stays a Workflow concern.** Approved.
Publishing actions pause via the existing `approval_gate` mechanism
(validated end-to-end in Item 12); no approval logic is duplicated
inside Publishing.

**Decision 4 — Continue using `ArticleRepositoryInterface`; no parallel
article table.** Approved. `WP_Post` + postmeta remains the system of
record — maximum compatibility with WordPress core, SEO/backup plugins,
import/export, REST API, and third-party integrations.

**Finalized architecture:**

```
WorkflowRunner
      │
      ▼
Publishing Actions   (Create Draft, Update Draft, Schedule Publish,
      │                Publish, Unpublish, Archive, Update Metadata,
      │                Notify Editor)
      ▼
DraftRepository / PublishingService
      │
      ▼
ArticleRepositoryInterface   (frozen, Module 3 — unchanged)
      │
      ▼
WP_Post
```

Clean separation, no leakage: Workflow orchestrates, Publishing provides
publishing logic, Storage persists, WordPress is the content platform.

## Still open

**Decision 5 — scope of "Revision handling."** WordPress's native post
revisions (free, zero work — `WP_Post` already has this) versus a
workflow-level "resubmit for approval" revision concept (a real feature
requiring design) are different things. Needs a call before that part of
the pipeline is built; not blocking the first milestone below.

---

## Milestone 1 (this delivery): migrations + service provider skeleton

Per the incremental, independently-verifiable pattern Module 7 used
throughout: storage first, tested and confirmed, before any action or
pipeline logic. See implementation below.


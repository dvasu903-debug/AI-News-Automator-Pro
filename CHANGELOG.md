# Changelog

All notable changes to AI News Automator Pro are documented here. The
project is pre-release; entries below cover the 2.0.0-dev line.

## [Unreleased — 2.0.0-dev]

### Added
- **SEO Engine (Module 9):** `SeoServiceProvider` (ninth in
  `ModuleManifest`) adds the plugin's first public, anonymous-visitor-
  facing render path (`wp_head`). `Seo\Services\MetaTagBuilder`
  constructs all SEO tag data (canonical URL via a live
  `CanonicalUrlResolver::resolve()`/`get_permalink()` call, Open Graph,
  Twitter Card, and `SchemaOrgGenerator`'s schema.org `NewsArticle`
  JSON-LD) from the frozen `DraftSeoRepositoryInterface` (read-only —
  `ana_draft_seo`, Publishing Milestone 1, previously unused); a new
  `Seo\Contracts\SeoProviderInterface` is the extensibility seam for
  future providers (Google Discover, WooCommerce, News SEO), currently
  bound solely to `DefaultSeoProvider`. `SeoHeadRenderer` is the
  module's only output boundary — it renders `MetaTagBuilder`'s data,
  escaping every field at its actual output context
  (`esc_attr()`/`esc_url()` for HTML attributes, `wp_json_encode(...,
  JSON_HEX_TAG | JSON_HEX_AMP)` for the JSON-LD block, which eliminates
  a `</script>` tag-breakout risk regardless of string content) rather
  than trusting any upstream sanitization. `InternalLinkSuggester`
  (admin-editor-only, deterministic, never calls `AIManager`) ranks
  other published posts by shared extracted-entity count with the
  current post's linked research session. `BreadcrumbGenerator` and
  `SeoHealthCheck` round out the module. See ADR-0020 for the full
  trust-boundary and extensibility-seam reasoning, and
  `planning/MODULE_9_SEO_ENGINE_DESIGN.md` for the architecture review
  that identified this as the correct next module and the design it
  followed.
- **Publishing (Module 8, Milestone 4):** The AI-generation pipeline
  ADR-0018 deferred. `Publishing\Services\AiContentGenerator`
  (`ContentGeneratorInterface`) turns a completed research session's
  `ResearchSummary` into sanitized draft content via `AIManager` — the
  AI only ever sees claim statements, never citation text; the
  generated body is sanitized with `wp_kses_post()` before any caller
  sees it, and deterministic citations are appended afterward with
  `esc_html()` around each one. `GenerateAction` (new Workflow action)
  creates the draft directly; on an `AIException` or
  `ContentGenerationException` it rethrows a classified
  `WorkflowStepException` so `WorkflowStepRetryExecutor` actually
  retries a transient provider failure (rate limit/outage) instead of
  losing that classification. `Publishing\Services\ResearchEditorialPolicy`
  is a second `EditorialPolicyInterface` implementation (not a new
  interface, not a swap of the existing `DefaultEditorialPolicy`
  binding) checking citation count/confidence/contradictions from the
  linked research session; `ValidateContentAction` merges its
  violations with the existing policy's. `PostProcessAction` derives
  SEO metadata (meta title/description, a heuristic focus keyword,
  default robots directives) via strictly deterministic string
  manipulation — never a second AI call — and persists it through the
  new `DraftSeoRepository` into the previously-unused `ana_draft_seo`
  table from Milestone 1. Two new events (`DraftGeneratedEvent`,
  `PublishingCompletedEvent`); `ValidateContentAction`/`PostProcessAction`
  are the first real use of `WorkflowRunContext::priorOutput()`. See
  ADR-0019 for the full trust-boundary and scope reasoning.
- **Publishing (Module 8, Milestone 3):** `PublisherInterface`/
  `PublishingService` (publish/schedule/unpublish/archive —
  `publish()` reuses the frozen `ArticleRepositoryInterface::approve()`
  for AI-generated drafts, `wp_update_post()` directly otherwise;
  `schedule()` uses WordPress-native `post_status=future`; `archive()`
  uses WordPress-native `post_status=private`), `EditorialPolicyInterface`/
  `DefaultEditorialPolicy` (AI-disclosure and word-count policy checks),
  four new Workflow actions (`PublishDraftAction`, `ScheduleDraftAction`,
  `UnpublishAction`, `ArchiveAction` — the first real use of the
  previously-unused `ActionRegistryInterface` extension point), six new
  Publishing events, `PublishingAbilityPolicy` (maps to existing
  `Capabilities` constants, no changes to that frozen class), a REST
  controller (`/publishing/profiles` list/create plus
  publish/schedule/unpublish/archive), and `PublishingHealthCheck`. See
  ADR-0018 for the full scope reasoning, including the "Planner"
  interpretation and the explicit deferral of the AI-generation
  pipeline to a future milestone.
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
- **Publishing (Module 8, Milestone 4):** the Hostinger smoke test
  script (`scripts/hostinger/milestone4-smoke-test.php`) fataled under
  `wp eval-file`, which evaluates the target file's content via PHP's
  `eval()` — this does not accept a leading `declare(strict_types=1)`
  as the file's true first statement. Fixed by removing it from the
  smoke-test script (every value in it is already explicitly cast, so
  no behavior depended on strict typing). Scoped entirely to that
  script; no `src/` file uses `wp eval-file` or is affected.
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
- Module 8 Milestone 3 (PublishingService/EditorialPolicy/Actions) full
  local pipeline (522 tests, 895 assertions, 1 documented incomplete)
  and two independent runtime passes — a local real-database harness
  covering all six required areas (PublishingService operations, REST
  endpoints, Workflow actions, event dispatch, authorization policies,
  health check registration) with explicit assertions, and a live
  Hostinger smoke test on the deployed artifact — both passed with no
  defects found; see
  docs/verification/2026-07-23-module-8-milestone-3-runtime-verification.md.
- Module 8 Milestone 4 (AI-generation pipeline) full local pipeline
  (557 tests, 1 documented incomplete, PHPCS clean on every new file)
  and two independent runtime passes — a local real-database harness
  exercising `GenerateAction → ValidateContentAction → PostProcessAction`
  end-to-end against a real `AIManager` (network call faked) and
  confirming the ADR-0019 citation-escaping trust boundary holds at
  runtime, and a live Hostinger smoke test on the deployed artifact —
  both passed. One defect found and fixed: `wp eval-file`'s
  incompatibility with `declare(strict_types=1)`, scoped to the
  smoke-test script only; see
  docs/verification/2026-07-23-module-8-milestone-4-runtime-verification.md.

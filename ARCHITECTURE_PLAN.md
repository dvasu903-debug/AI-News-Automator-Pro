# AI Publishing Engine — Full Audit & Architecture Plan

> **Terminology.** This document describes the **AI Publishing Engine**: the generic, modular automated-publishing architecture. The commercial WordPress product built on this engine is **AI News Automator Pro**, whose first (and shipping) vertical is *News*. Throughout this document, "the engine" / "AI Publishing Engine" refers to the reusable platform; "the plugin" / "AI News Automator Pro" refers to the product. Code identifiers (namespace `AINewsAutomator\`, `ana_` prefixes, option/meta keys, REST namespaces, text domain) are retained unchanged for backward compatibility — this rename is architectural terminology only and introduces no breaking changes.

Status: **planning document, no code.** Covers the full engine per your spec. Module 1 (Bootstrap & Core) has already been built and reflects the Container/Lifecycle design described here — this document formalizes the plan the rest of the modules follow. News-specific behavior is layered as a *vertical* on top of the engine modules rather than embedded within them.

---

## PART 1 — AUDIT OF THE EXISTING PLUGIN (v1.1, monolithic)

The "existing plugin" here is the single-file-per-class `ANA_*` version built before this rebuild started. Auditing it in full, by category.

### 1.1 Architectural weaknesses

| # | Weakness | Why it matters |
|---|---|---|
| A1 | No dependency injection — every class does `new ANA_Settings()` inline | Impossible to unit test in isolation; impossible to swap implementations (e.g. a different settings backend) without editing every call site |
| A2 | No interfaces anywhere | `ANA_Pipeline` is compiled directly against "Claude via wp_remote_post" — there is no `AIProviderInterface` to implement a second provider against |
| A3 | No namespacing — global `ANA_` prefix | Standard WordPress-plugin collision avoidance, but it means there's no PSR-4 mapping, no autoloading, and `require_once` order is manually maintained and easy to break |
| A4 | One class does multiple jobs | `ANA_Pipeline` fetches stories, calls the fact-check API, calls the writing API, parses two different JSON shapes, and creates the WordPress post — five responsibilities in one class, violating single-responsibility |
| A5 | No event/observer system | Nothing else in the plugin (or a third-party plugin) can hook into "a draft was just created" without editing `ANA_Pipeline` directly |
| A6 | No repository pattern for settings | `ANA_Settings::get_settings()` is a static call scattered through five other classes — there's no single seam to swap "settings live in wp_options" for "settings live in a dedicated table" later |
| A7 | No queue — pipeline runs synchronously inside the cron request | A slow AI response or a hung HTTP request blocks the entire cron execution; if the run exceeds PHP's `max_execution_time`, everything after the timeout silently never runs, with no retry |
| A8 | No factory or strategy pattern for AI providers | Provider selection isn't a configuration switch, it's a code edit |

### 1.2 Security weaknesses

| # | Weakness | Why it matters |
|---|---|---|
| S1 | API keys stored as plaintext in `wp_options` | Anyone with DB read access (a compromised plugin, a backup leak, a support tech) gets the raw Anthropic/NewsAPI/Unsplash keys |
| S2 | No rate limiting on `admin-post` actions (`ana_run_now`, `ana_approve_draft`, `ana_reject_draft`) | A logged-in low-privilege user with a stolen/leaked nonce, or a compromised admin session, could trigger unlimited pipeline runs and burn API budget |
| S3 | No audit log of who approved/rejected/ran what, only a generic run log | On a real news site, "who published this" needs to be answerable definitively, not inferred |
| S4 | Capability checks exist but are coarse (`manage_options` / `publish_posts` / `delete_posts`) | Fine for a single-admin site; doesn't support an editorial team with different trust levels (e.g. a reviewer who can approve but not change settings) |
| S5 | No validation that AI-returned JSON is well-formed before use beyond a try/catch fallback to empty defaults | A malformed or adversarially-crafted API response degrades silently into an empty draft rather than being flagged loudly |

### 1.3 Performance weaknesses

| # | Weakness | Why it matters |
|---|---|---|
| P1 | No caching of trend/RSS fetches | Every cron run re-fetches the same feeds even if nothing changed since the last run |
| P2 | No batching — one HTTP request per story, sequentially | 3 stories × 2 AI calls each = 6 sequential blocking HTTP round-trips per cron run |
| P3 | No image optimization | Sideloaded Unsplash images are stored at their original size/format, no WebP conversion, no responsive sizes generated beyond WordPress's own defaults |
| P4 | No deduplication against already-published stories | The "merge, dedupe, score" step only dedupes within a single run's results, not against the site's existing published content — the same underlying story could be drafted twice across separate runs |

### 1.4 Code quality / technical debt

| # | Issue | Detail |
|---|---|---|
| T1 | No strict typing | No `declare(strict_types=1)`, no parameter/return type hints in most methods |
| T2 | No PHPDoc on most methods | Several methods have no documented parameter/return shapes, especially the JSON payload shapes returned by the AI calls |
| T3 | No automated tests | Zero PHPUnit coverage; every change is a manual click-through |
| T4 | No coding standard enforcement | No PHPCS/WPCS configuration, so style drifts file to file |
| T5 | Duplicated logic | The Claude-call-and-JSON-parse pattern is copy-pasted between `fact_check_story()` and `write_article()` instead of extracted into one method |
| T6 | Duplicated logic across files | The self-test/diagnostics class re-implements its own Claude/NewsAPI/Unsplash HTTP calls rather than reusing the pipeline's own methods, so a change to auth headers has to be made in two places |
| T7 | Magic strings everywhere | Meta keys (`_ana_generated`, `_ana_confidence`, etc.), option names, and status strings are inline string literals repeated across files with no single source of truth |
| T8 | No versioned upgrade path | No mechanism to detect "this site has v1.0 data, migrate it to v1.1's schema" — works by accident here because both versions use the same meta keys, but there's no general solution for a future breaking change |
| T9 | No dedicated database schema | Everything lives in `wp_options` (flat arrays, no indexing, no querying by date range/level/module) or `post_meta` (fine for post-specific data, not fine as a substitute for a real queue/log table) |

### 1.5 What was already good (kept in the new design)

- Human-approval-before-publish as the default posture
- Fact-check-before-write ordering (never write from unverified claims)
- Non-fatal degradation (missing image API key doesn't block the post)
- Real, working diagnostics concept (being generalized into the Monitoring module)

---

## PART 2 — NEW ARCHITECTURE PLAN

### 2.1 Folder structure

```
ai-news-automator-pro/
├── ai-news-automator-pro.php        # WordPress entry point — bootstrap only, no logic
├── uninstall.php                     # Delegates to Core\Uninstaller
├── composer.json
├── phpunit.xml.dist
├── .phpcs.xml.dist                   # WPCS ruleset (Module 2+)
├── README.md
│
├── src/
│   ├── Core/                         # ✅ built — kernel, container, lifecycle, contracts
│   │   ├── Contracts/
│   │   ├── Exceptions/
│   │   └── Logging/
│   │
│   ├── Security/                     # Module 2 — capability middleware, nonce helpers,
│   │   ├── Contracts/                #   encrypted credential storage, rate limiter, audit log
│   │   ├── CapabilityGate.php
│   │   ├── NonceVerifier.php
│   │   ├── CredentialVault.php       # encrypts API keys at rest (Module 2)
│   │   ├── RateLimiter.php
│   │   └── AuditLogger.php
│   │
│   ├── Storage/                      # Module 3 — custom tables + repository pattern
│   │   ├── Contracts/
│   │   │   └── RepositoryInterface.php
│   │   ├── Schema/                   # dbDelta table definitions, one file per table
│   │   ├── Repositories/             # SettingsRepository, DraftRepository, LogRepository, QueueRepository
│   │   └── Migrations/               # versioned schema upgrades
│   │
│   ├── AI/                           # Module 4 — provider strategy pattern
│   │   ├── Contracts/
│   │   │   └── AIProviderInterface.php
│   │   ├── Providers/                # ClaudeProvider, OpenAIProvider, GeminiProvider,
│   │   │                             #   DeepSeekProvider, OpenRouterProvider, OllamaProvider
│   │   ├── AIProviderFactory.php     # reads config, returns the bound provider
│   │   └── PromptTemplates/
│   │
│   ├── Sources/                      # Module 5 — source connector strategy pattern
│   │   ├── Contracts/
│   │   │   └── SourceConnectorInterface.php
│   │   └── Connectors/               # RssConnector, NewsApiConnector, GoogleNewsConnector,
│   │                                 #   YouTubeConnector, GitHubConnector, RedditConnector,
│   │                                 #   ProductHuntConnector, CustomSiteConnector
│   │
│   ├── Research/                     # Module 6
│   │   ├── FactMerger.php
│   │   ├── Deduplicator.php
│   │   ├── CredibilityRanker.php
│   │   ├── ContradictionDetector.php
│   │   └── CitationGenerator.php
│   │
│   ├── Queue/                        # Module 7a
│   │   ├── Contracts/
│   │   │   └── JobInterface.php
│   │   ├── QueueManager.php
│   │   ├── RetryPolicy.php
│   │   └── Jobs/                     # one class per job type (FetchSourceJob, FactCheckJob, WriteArticleJob, ...)
│   │
│   ├── Scheduler/                    # Module 7b
│   │   ├── CronScheduleRegistrar.php
│   │   └── PipelineTrigger.php
│   │
│   ├── Pipeline/                     # Module 8 — orchestration only, delegates to
│   │   ├── PipelineOrchestrator.php  #   AI/Sources/Research/Queue, contains no business logic itself
│   │   └── Events/                   # StoryDiscovered, FactCheckCompleted, DraftCreated, etc.
│   │
│   ├── SEO/                          # Module 9
│   │   ├── SchemaGenerator.php
│   │   ├── OpenGraphGenerator.php
│   │   ├── TwitterCardGenerator.php
│   │   ├── InternalLinkSuggester.php
│   │   └── BreadcrumbGenerator.php
│   │
│   ├── Images/                       # Module 10
│   │   ├── Contracts/
│   │   │   └── ImageSourceInterface.php
│   │   ├── Sources/                  # UnsplashSource, AIGeneratedSource (illustrative only)
│   │   ├── ImageOptimizer.php        # compress, resize, WebP conversion
│   │   └── ThumbnailGenerator.php
│   │
│   ├── Publishing/                   # Module 11
│   │   ├── PublishingRulesEngine.php
│   │   ├── ApprovalWorkflow.php
│   │   ├── Scheduler.php
│   │   └── RollbackManager.php
│   │
│   ├── Social/                       # Module 12
│   │   └── Connectors/               # per-platform sharing connectors
│   │
│   ├── Analytics/                    # Module 13
│   │   ├── Contracts/
│   │   │   └── AnalyticsProviderInterface.php
│   │   ├── Providers/                # GA4Provider, SearchConsoleProvider, InternalProvider
│   │   └── CostTracker.php           # API spend tracking across AI providers
│   │
│   ├── Dashboard/                    # Module 14a — admin UI
│   │   ├── Pages/                    # one class per admin screen
│   │   └── Widgets/                  # chart/stat components reused across pages
│   │
│   └── Monitoring/                   # Module 14b — supersedes Core's OptionBackedLogger
│       ├── DatabaseLogger.php        # implements Core\Contracts\LoggerInterface
│       ├── HealthScoreCalculator.php
│       └── QueueMonitor.php
│
├── assets/
│   ├── admin/                        # CSS/JS for the dashboard, built per-module as needed
│   └── public/                       # any front-end assets (rare — mostly an admin-facing plugin)
│
├── tests/
│   ├── bootstrap.php
│   ├── Unit/                         # mirrors src/ structure, one test class per class
│   └── Integration/                  # WP_UnitTestCase-based, added once Storage exists
│
└── vendor/                           # Composer-managed, not committed
```

**Design rule enforced by this structure:** every module owns exactly one top-level `src/` directory, exposes exactly one `ServiceProviderInterface` implementation at its root, and only communicates with other modules through interfaces in `Contracts/` — never by reaching into another module's concrete classes directly. `Pipeline/` is the one deliberate exception in spirit, not in mechanism: it's still just a consumer of other modules' interfaces, it just happens to be the module that ties them together in sequence.

### 2.2 Dependency Injection system design

**Constructor injection only.** No property injection, no setter injection, no service-locator calls (`Plugin::instance()->container()->get(...)`) from inside business logic — that last one is a deliberate rule because it hides a class's real dependencies from its constructor signature, which defeats the point of DI. The *only* place the container is ever fetched via the static `Plugin::instance()` accessor is the top-level bootstrap file and the three lifecycle classes (Activator/Deactivator/Uninstaller), because those have no constructor of their own to be injected into.

**Interfaces vs. concrete autowiring.** Two kinds of things get resolved:
1. **Concrete classes with no ambiguity** (e.g. `FactMerger`, `Deduplicator`) — never explicitly bound, the container's reflection-based autowiring builds them on demand.
2. **Anything with more than one possible implementation** (e.g. `AIProviderInterface`, `SourceConnectorInterface`, `ImageSourceInterface`, `LoggerInterface`) — always explicitly bound in that module's `ServiceProviderInterface::register()`, reading from configuration to decide which concrete class to bind.

**Tagged/collection bindings (new in this plan, not yet built).** Some things need "give me *every* registered implementation," not "give me *the* implementation" — e.g. the Sources module needs every registered `SourceConnectorInterface` to poll all of them each run, not just one. Module 1's `Container` doesn't support this yet. Module 5 (Sources) will need a small, additive extension to `Container`: a `tag(string $tag, array $ids)` / `tagged(string $tag): array` pair, so `SourceAggregator` can request `container.tagged('source.connectors')` and get all of them, each still individually autowired. This is the one piece of Module 1's container that needs revisiting — flagging it now rather than surprising you mid-Module-5.

**Configuration-driven provider selection.** `AIProviderFactory::register()` will read a single settings value (`ai_provider` = `claude` | `openai` | `gemini` | ...) and bind `AIProviderInterface` to the matching concrete class. Every consumer of `AIProviderInterface` — the writer, the fact-checker, future content-type generators — never knows or cares which provider is behind it. Switching providers is changing one dropdown in Settings, exactly as your spec requires.

### 2.3 Service container design (recap + planned extensions)

Already built in Module 1, kept as the foundation:
- `bind()` / `singleton()` / `instance()` — three binding lifecycles
- Reflection-based constructor autowiring with recursive resolution
- `NotFoundException` vs `ContainerException` — distinguishes "never registered" from "registered but failed to build"

Planned extensions, to be built in the module that first needs them (not speculatively now):
- **Tagging** (`tag()`/`tagged()`) — needed starting Module 5 (Sources), as above.
- **Contextual binding** (rare, deferred until proven needed) — e.g. "when building `ClaudeProvider`, inject *this specific* HTTP client timeout config" rather than the global default. Only adding this if a real case in a later module actually needs it — speculative flexibility here would violate KISS for no current benefit.
- **Compiled container for production** (deferred until performance profiling shows it's needed) — reflection-based autowiring has a small per-request cost; a compiled/cached container (resolve the dependency graph once, write it to a generated PHP file) is a standard optimization if profiling ever shows it matters. Not building this preemptively.

### 2.4 Autoloader design

- **Composer PSR-4 is the only autoloading mechanism** — no manual `require_once` chains anywhere in `src/`. The single `require_once __DIR__ . '/vendor/autoload.php'` in the bootstrap file is the only manual include in the entire plugin (plus the mirrored one in `uninstall.php`, since WordPress calls that file independently).
- **Namespace root:** `AINewsAutomator\` maps to `src/`, exactly matching the existing Module 1 mapping — every subsequent module's namespace (`AINewsAutomator\Security\`, `AINewsAutomator\Storage\`, etc.) falls out of this automatically with zero extra configuration.
- **No autoloading dependency on WordPress function availability.** Classes in `src/` must be loadable (the class definition itself must parse and autoload) even outside a WordPress request — actual *execution* of WordPress-dependent code paths is guarded at runtime, but the autoloader itself never assumes `WPINC` is defined. This is what makes the PHPUnit unit tests (no WordPress bootstrap) possible at all.
- **`vendor/` is never committed to version control**, consistent with standard Composer practice — the plugin as *distributed* to a production site needs `composer install --no-dev --optimize-autoloader` run as a build step, producing a classmap-optimized autoloader rather than the slower PSR-4 filesystem-lookup autoloader used in development.

### 2.5 Plugin lifecycle design

**Activation** (`register_activation_hook`) → `Activator::activate()`:
1. Boot the kernel (all providers register + boot, so bindings exist)
2. Call `activate()` on every provider implementing `ActivatableInterface`, in provider-registration order (Storage's tables must exist before Queue tries to reference a queue table, so registration order in the manifest array *is* meaningful and will be documented inline in the manifest once those modules exist)
3. Stamp the installed version into an option (enables future upgrade-routine detection)
4. Flush rewrite rules

**Deactivation** (`register_deactivation_hook`) → `Deactivator::deactivate()`:
1. Boot the kernel
2. Call `deactivate()` on every `ActivatableInterface` provider — unschedule cron events, pause queue workers — without deleting any data
3. Flush rewrite rules

**Uninstall** (`uninstall.php`, WordPress-invoked only on explicit delete-via-wp-admin):
1. Boot the kernel
2. Call `uninstall()` on every `ActivatableInterface` provider — drop tables, delete options, clear transients
3. This is the only lifecycle stage that destroys data, by design

**Normal request boot** (`plugins_loaded`):
1. `Plugin::instance()` constructs the `Container`
2. Every provider class in the manifest array is instantiated and its `register()` called (bindings only, no WordPress hooks yet)
3. Every provider's `boot()` is then called (this is where `add_action`/`add_filter` calls actually happen)
4. From this point on, the plugin behaves as a normal set of WordPress hook callbacks — the DI system's job is finished for the request, everything downstream is ordinary WordPress execution

**Upgrade routine (new in this plan, to be built in Module 3 alongside Storage):** on `plugins_loaded`, compare the stored `ai_news_automator_version` option against `ANA_PRO_VERSION`. If older, run any pending `Storage\Migrations\*` classes in version order before the rest of boot proceeds, then update the stored version. This didn't exist in the v1.0/v1.1 plugin (technical debt item T8) and needs to exist before the plugin has any real schema to migrate.

**Background/queue lifecycle (Module 7, distinct from the request lifecycle above):** the current cron hook fires the whole pipeline synchronously in one request (inherited weakness P2/A7 from the audit). The new design separates "cron fires" from "work happens": the scheduled event only enqueues jobs (fast, non-blocking); a separate short-interval cron event (or WP Cron's queue-runner pattern, action-scheduler-style) pulls a small batch of pending jobs and executes them with a retry policy on failure, so one slow AI call can't block story #2 and #3 behind it, and a transient failure gets retried instead of silently dropped.

---

## Open questions for your approval

1. **Container tagging extension** (2.3) — confirm you're fine with this being added when Module 5 needs it, rather than retrofitted into Module 1 now.
2. **Module build order** — this plan assumes Security (2) → Storage (3) → AI (4) → Sources (5) → Research (6) → Queue/Scheduler (7) → Pipeline (8) → SEO/Images/Publishing (9–11) → Social/Analytics (12–13) → Dashboard/Monitoring (14), since each depends on the previous. Confirm or reorder.
3. **Migration system placement** (2.5) — proposing it lives in Storage rather than its own module, since it's fundamentally schema-versioning for Storage's tables. Confirm or split it out.

Waiting for your go-ahead before writing any Module 2 code.

---

# ADDENDUM — Module 1.1 Foundation Freeze

This addendum records the architectural decisions finalized in the 1.1 refinement. Where it conflicts with the original plan above, the addendum wins.

## Composition root replaces the singleton

The original plan had `Plugin::instance()` as a "deliberately the only singleton." That is now removed. The composition root is `PluginFactory::create()`, called once per WordPress entry point. Rationale: even one singleton legitimizes service-location (`Plugin::instance()->container()->get()`) and blocks per-test isolation. There is now zero global mutable state; the only file-scope values are two constants (`ANA_PRO_VERSION`, `ANA_PRO_FILE`) read in exactly one method.

## Container capabilities are now concrete, not "extension points"

The original plan listed tagging/aliases/lazy/circular-detection as "documented extension points, build when needed." They are now all implemented in `Container`, because the freeze instruction requires the foundation not to need later rewrites. All additive; 1.0 behavior unchanged.

- **Tagging** (`tag`/`tagged`): the Sources module (5) will register each connector under a `source.connectors` tag and its aggregator resolves `tagged('source.connectors')`.
- **Aliases** (`alias`): the AI module (4) will alias `AIProviderInterface` to the configured concrete provider.
- **Lazy** (`lazy` + `LazyProxy`): for expensive, conditionally-used services (e.g. HTTP clients).
- **Circular detection**: throws `CircularDependencyException` with the full chain.

## Configuration is an injected object, not constants

`PluginConfig` (version, paths, URLs, environment, text domain, feature flags) is registered in the container by `PluginFactory` before boot. `Environment` (enum) drives environment-sensitive behavior (e.g. debug-log suppression in production). `FeatureFlags` enables staged rollouts. `SecretsProviderInterface` is the seam for encrypted secrets — contract in Core, implementation in Security (Module 2).

## Correlation IDs span logs and events

A single `CorrelationContext` (container singleton) supplies a shared correlation ID to both `OptionBackedLogger` (every entry) and `EventMetadataFactory` (every event). A queued job adopts its originating request's ID so background work correlates back to its origin. This is the backbone for tracing a single pipeline run across its many asynchronous stages once Queue (7) and Pipeline (8) exist.

## Event dispatcher stays metadata-agnostic

Events carry an `EventMetadata` envelope (id, timestamp, correlation ID, source module, context), but the dispatcher reads none of it — it dispatches any object and remains reusable/loosely-coupled. Metadata is the emitter's responsibility, produced via `EventMetadataFactory`.

## Build & release pipeline

Development uses Composer; production never does. `bin/build.sh` produces a self-contained ZIP with an optimized classmap autoloader and no dev dependencies. `.github/workflows/build.yml` tests on PHP 8.2/8.3 and, on a `v*` tag, builds and attaches the ZIP to a GitHub Release. This resolves the original plan's note that production installs must not require a build step on the target site.

## Boot sequence (updated)

```
WordPress loads main plugin file
  → defines ANA_PRO_VERSION, ANA_PRO_FILE
  → requires vendor/autoload.php (optimized classmap in production)
  → registers 3 entry-point closures (activate / deactivate / plugins_loaded)

On plugins_loaded:
  PluginFactory::create(ANA_PRO_FILE)
    → new Container
    → PluginConfig::fromPluginFile(...)  (reads the 2 constants, once)
    → container.instance(PluginConfig)
    → new Plugin(container, ModuleManifest::providers())
  Plugin::boot()
    → phase 1: every provider->register(container)   (bindings only)
    → phase 2: every provider->boot(container)        (WordPress hooks)

On activation/deactivation:
  same PluginFactory::create(), wrapped in Activator/Deactivator,
  which boot() then call activate()/deactivate() on ActivatableInterface providers.

On uninstall (uninstall.php, loaded independently by WordPress):
  same factory → Uninstaller → uninstall() on ActivatableInterface providers.
```

---

# MODULE 2 COMPLETE — Security

Security is implemented as a single `SecurityServiceProvider` (manifest position 2, after Core). Key integration facts for later modules:

- **Authorization**: depend on `CapabilityGateInterface`; call `allows($ability)` / `authorize($ability)`. Never call `current_user_can` directly. Register custom policies by binding a `PolicyInterface` and tagging it `security.policies`.
- **Secrets**: depend on Core's `SecretsProviderInterface` (bound to `CredentialVault`); for metadata use the concrete `CredentialVault`.
- **Outbound URLs**: depend on `OutboundHttpValidator` (or `UrlGuardInterface`) — mandatory for any user-supplied URL fetch (Sources module).
- **Request guarding**: admin-post/AJAX handlers use `RequestValidatorInterface::validate()`; REST controllers use `RestSecurityMiddleware`.
- **Events available to consume**: `PermissionDeniedEvent`, `RateLimitExceededEvent`, `SecretAccessedEvent`, `SuspiciousRequestEvent`, `ThreatDetectedEvent` (all extend Core `AbstractEvent`, flow through the Core dispatcher).
- **Storage seam**: `AuditLogRepositoryInterface` is option-backed now; Module 3 provides a table-backed binding.

Custom capabilities installed on activation: `ana_manage_settings`, `ana_manage_security`, `ana_manage_sources`, `ana_approve_content`, `ana_run_pipeline`, `ana_view_analytics`, `ana_view_audit_log`.

Full component list, threat model, and limitations: `src/Security/README.md`.

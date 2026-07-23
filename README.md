# AI News Automator Pro

> **Product:** AI News Automator Pro (WordPress plugin).
> **Architecture:** the **AI Publishing Engine** — a generic, modular automated-publishing platform. "News" is one *vertical* built on top of the engine; the same foundation is designed to power blogs, affiliate sites, documentation, product catalogs, and other automated publishing workflows.
>
> The commercial product is branded *AI News Automator Pro*. Internally, the platform and all shared infrastructure are the *AI Publishing Engine*. Code identifiers (the `AINewsAutomator\` namespace, the `ana_` data prefixes, option/meta keys, REST namespaces, and text domain) are retained unchanged for backward compatibility — the rename is architectural terminology only, never a breaking change.

Enterprise-grade AI publishing platform for WordPress, built as the AI Publishing Engine. Being built module-by-module per the architecture spec — this package currently contains the engine foundation (**Module 1: Core**, with the **Module 1.1 foundation-freeze refinements**) and **Module 2: Security**. See `MIGRATION-1.1.md` for the 1.1 change list.

## Status: Engine foundation — Modules 1 (+1.1) and 2 complete

| # | Module | Status |
|---|---|---|
| 1 | **Core** (bootstrap, loader, container, DI, config, logger, events, REST base, settings framework) | ✅ Done + 1.1 frozen |
| 2 | **Security** (policy engine, capabilities, nonces, vault/encryption, rate limit, audit, threat detection, SSRF guard, webhooks, health) | ✅ Done |
| 3 | **Storage** (repositories, migrations, transactions, query builder, retention, rebinds Audit/Logger/Metrics) | ✅ Done |
| 4 | **AI Provider Engine** (7 providers, orchestration, failover, retry classification, cost/metrics via Storage) | ✅ Done |
| 5 | **Sources** (RSS/JSON/crawler/sitemap connectors, dedup, narrow retry/scheduler) | ✅ Done |
| 6 | **Research Engine** (sessions, claims, entities, citations, contradiction detection, confidence scoring — never publishes) | ✅ Done |
| 7 | Workflow Engine | Awaiting approval |
| 8 | Publishing Engine | Awaiting approval |
| 7 | Queue & Scheduler (background jobs, retries) | Not started |
| 8 | Pipeline (orchestration across the above) | Not started |
| 9 | SEO (schema, OG, Twitter Cards, internal links) | Not started |
| 10 | Images (download/generate/compress/WebP) | Not started |
| 11 | Publishing (rules engine, approval workflow, rollback) | Not started |
| 12 | Social | Not started |
| 13 | Analytics | Not started |
| 14 | Dashboard & Monitoring | Not started |
| 15 | Tests (integration suite across all modules) | Unit tests for Module 1 only so far |

## Engine vs. verticals

Modules 1–14 are the **AI Publishing Engine**: generic, domain-agnostic infrastructure. None of them assume the content being produced is "news" — they discover sources, research, fact-check, generate, optimize, and publish *content* of any kind.

**Verticals** sit on top of the engine and supply the domain specifics — prompt templates, source presets, content-type rules, schema choices. **News** is the first vertical (and the one the commercial product, *AI News Automator Pro*, ships with), but the engine is deliberately built so blogs, affiliate sites, documentation, and product catalogs can each be added as additional verticals without changing engine code. Vertical-specific code is intended to live under a dedicated namespace/directory (e.g. a future `Verticals\News`) rather than being baked into engine modules, keeping the engine reusable.

## What's in Module 1 (Core)

**Bootstrap** — `ai-news-automator-pro.php`. Thin entry point only: defines constants, loads the Composer autoloader, registers activation/deactivation hooks, and boots the kernel on `plugins_loaded`. Contains no business logic.

**Plugin Loader** — `ModuleManifest`. Decides *which* modules are active, separately from `Plugin` (the kernel), which only knows *how* to run whatever providers it's given. Filterable via `ai_news_automator_active_providers` so a module can be disabled without editing the bootstrap file.

**Service Container / Dependency Injection** — `Container`. Full `bind()`/`singleton()`/`instance()` plus reflection-based constructor autowiring, recursively resolving typed class/interface parameters. Concrete classes need no explicit binding; only interfaces with more than one possible implementation do.

**Configuration Manager** — `ConfigRepositoryInterface` / `OptionBackedConfigRepository`. Dot-notation access (`config.get('logging.max_entries')`) over a defaults array (`Config/config-defaults.php`) merged with a single persisted overrides option. Distinct from the Settings Framework below — Config is for internal system values with code-defined defaults, Settings is for user-facing admin forms.

**Logger** — `LoggerInterface` / `OptionBackedLogger`. PSR-3-shaped, full level support, message interpolation, rotation. Explicitly documented as the piece Module 14 (Monitoring) will supersede with a dedicated DB table, via a one-line container rebinding — no other code will need to change.

**Event Dispatcher** — `EventDispatcherInterface` / `EventDispatcher` / `AbstractEvent`. Internal pub/sub for cross-module communication (distinct from WordPress's own hooks, which remain how the plugin talks to WordPress core and other plugins). Priority-ordered, class-hierarchy-aware (a listener on an interface fires for any implementing event), supports propagation stopping.

**REST API Base** — `RestControllerInterface` / `AbstractRestController` / `RestApiRegistry`. Standardized success/error response helpers and a capability-based permission-callback helper. No concrete endpoints exist yet — this is infrastructure for later modules to build their controllers against.

**Settings Framework** — `SettingsField` / `SettingsSection` / `AbstractSettingsPage` / `SettingsRegistry`. Fixes the old plugin's single god-class settings page: a module now defines only its fields (with type-aware sanitization built in) and a small subclass inherits full WordPress Settings API wiring — menu registration, form rendering, sanitize-on-save.

All of the above wire together in `CoreServiceProvider`, the one service provider Core exposes at its root, consistent with the "one provider per module" rule from the architecture plan.

## Local setup (development)

```bash
composer install
composer test    # PHPUnit — Container, Logger, Config, Events, PluginConfig, SettingsField
composer lint    # PHPCS against WordPress Coding Standards
```

## Building a production release

Production users never run Composer. To produce an installable ZIP:

```bash
bin/build.sh              # version read from the plugin header
bin/build.sh 2.0.0        # or pass an explicit version
# -> dist/ai-news-automator-pro-<version>.zip
```

The ZIP bundles an optimized classmap autoloader and production-only
dependencies, and unzips to a single `ai-news-automator-pro/` folder that
installs directly via **Plugins → Add New → Upload Plugin**. Pushing a
`v*` git tag triggers the GitHub Actions workflow (`.github/workflows/build.yml`)
to run the tests and attach the built ZIP to a GitHub Release automatically.

I could not run `composer install`, the test suite, or the build here — no
network access in this environment. I validated structurally instead: brace/paren
balance across every PHP file, every namespace matches its path (PSR-4), every
import resolves, and no lingering singleton/scattered-constant references remain.
Run `composer test` locally before building a release.

## What Module 1 deliberately does NOT include

No AI calls, no source fetching, no publishing, no concrete REST endpoints or settings pages (those belong to the modules that will actually have fields/routes to expose) — this module only proves the skeleton every other module will plug into.

---

Ready for your review. Tell me if you want changes to this module, or say "approved" / "next module" and I'll move to **Module 2: Security** (capability checks, nonce middleware, encrypted API key storage, rate limiting, audit logging) — since every later module that exposes an admin action or stores a credential will depend on it.

# Module 1.1 — Migration Notes

What changed from the Module 1.0 Core foundation, and why. Grouped by the seven required refinements.

## 1. Plugin singleton removed

**Before:** `Plugin::instance()` returned a lazily-created, statically-cached kernel. `Activator`/`Deactivator`/`Uninstaller` each called `Plugin::instance()` to get it. A `resetForTesting()` static method existed purely to clear that global between tests.

**After:**
- `Plugin` has no static state, no `instance()`, no `resetForTesting()`. It takes its `ContainerInterface` and the provider manifest through its constructor.
- A new `PluginFactory::create()` is the single composition root. It builds a fresh `Container`, constructs and registers `PluginConfig`, and returns a `Plugin`.
- The bootstrap file calls `PluginFactory::create()` inside each WordPress entry-point closure (activation, deactivation, `plugins_loaded`). Only one entry point fires per request, so there's no duplicate construction and no cached global needed.
- `Activator`/`Deactivator`/`Uninstaller` now receive a `Plugin` via their constructor instead of fetching a singleton.

**Why:** global singletons are shared mutable state — untestable without teardown, and they invite `Plugin::instance()->container()->get()` service-location from anywhere, which hides real dependencies. Removing the singleton is what lets every test construct its own isolated container with no global to reset.

**Breaking?** Yes, for any code calling `Plugin::instance()` — but the only callers were the three lifecycle classes, all updated. No module code outside Core existed yet.

## 2. Production build system added

**New files:** `bin/build.sh`, `.github/workflows/build.yml`, `.gitignore`.

- `bin/build.sh` stages only distributable files (via rsync include/exclude), runs `composer install --no-dev --optimize-autoloader --classmap-authoritative` into the staging vendor dir, strips Composer metadata, and zips a single top-level `ai-news-automator-pro/` folder — the shape WordPress expects.
- The GitHub Actions workflow runs PHPUnit + PHPCS on PHP 8.2/8.3 for every push/PR, and on a `v*` tag additionally builds the ZIP and attaches it to a GitHub Release.

**Why:** production users install a ZIP; they must never run Composer. The build vendors an optimized classmap autoloader so the plugin is self-contained. `composer.json` remains for development only.

## 3. Container extended

**Added (all backward-compatible — every 1.0 method behaves identically):**
- `alias($alias, $target)` — resolve one id via another (e.g. point an interface at the configured concrete).
- `tag($id, $tag)` / `tagged($tag)` — register and resolve a whole collection. This is the mechanism flagged in the architecture plan as required for the Sources module; it now exists so Module 5 needs no container change.
- `lazy($id, $closure)` — returns a `LazyProxy` that defers construction until first use.
- **Circular dependency detection** — `build()` tracks the in-progress resolution stack and throws `CircularDependencyException` with the full chain instead of exhausting memory.

**Why now:** the instruction to "freeze the foundation." These are exactly the container capabilities later modules need; adding them now means no container rewrite mid-build. They're additive, so nothing from 1.0 breaks.

## 4. Central configuration object

**New:** `PluginConfig`, `Environment` (enum), `FeatureFlags`, plus a `SecretsProviderInterface` contract.

- `PluginConfig` owns version, plugin file/dir/url paths, environment, text domain, and feature flags. Built once in `PluginFactory` and registered as a shared container instance.
- The two file-scope constants that remain (`ANA_PRO_VERSION`, `ANA_PRO_FILE`) are read in exactly one place — `PluginConfig::fromPluginFile()` — and nowhere else. `ANA_PRO_DIR` and `ANA_PRO_URL` constants are gone entirely, replaced by `$config->path()` / `$config->url()`.
- `SecretsProviderInterface` is defined in Core but intentionally has no concrete implementation yet — Module 2 (Security) supplies the libsodium-backed one. Defining the contract now gives a stable seam for encrypted secrets.

**Why:** constants are un-substitutable global state. An injected config object is testable (construct one with fake paths) and makes a class's config dependency visible in its constructor.

## 5. Logging improvements

**Changed:** `OptionBackedLogger` now takes `CorrelationContext` + `Environment` in its constructor. Every entry carries `correlation_id` and the raw structured `context` array (not just the interpolated string). Added a `LogLevel` enum (the eight PSR-3 levels with severity ordering). Debug entries are suppressed in production, always kept in development.

**Why:** correlation IDs tie together every log line from one logical unit of work (a pipeline run that spans many stages), so a failure three stages deep can be traced to its origin. Preserving raw context enables downstream filtering/aggregation. The enum replaces loose string-level validation.

## 6. Event improvements

**Changed:** `AbstractEvent` now requires an `EventMetadata` envelope carrying event ID, timestamp, correlation ID, source module, and optional context. New `EventMetadataFactory` stamps events consistently, pulling the correlation ID from the shared `CorrelationContext`.

**Crucially:** the `EventDispatcher` itself was NOT changed to know about metadata — it still dispatches any `object`, reads no metadata fields, and stays fully module-agnostic and loosely coupled. Metadata lives on the events and is produced by emitters, exactly as required.

**Why:** every event being self-describing (who emitted it, when, as part of what correlated flow) is what makes an event stream auditable and debuggable without the dispatcher having to impose structure.

## 7. Documentation

`README.md` and `ARCHITECTURE_PLAN.md` updated to reflect the singleton removal, the config object, the build system, and the container extensions. This migration-notes file is new.

---

## Net file changes

**New:** `PluginFactory`, `Config/PluginConfig`, `Config/Environment`, `Config/FeatureFlags`, `Contracts/SecretsProviderInterface`, `LazyProxy`, `Exceptions/CircularDependencyException`, `Logging/LogLevel`, `Support/CorrelationContext`, `Events/EventMetadata`, `Events/EventMetadataFactory`, `bin/build.sh`, `.github/workflows/build.yml`, `.gitignore`, this file.

**Rewritten:** `Plugin` (no singleton), `Container` (extensions), `Contracts/ContainerInterface` (new methods), `CoreServiceProvider` (new registrations), `OptionBackedLogger` (correlation/context), `Events/AbstractEvent` (metadata), `Activator`/`Deactivator`/`Uninstaller` (injected Plugin), bootstrap + `uninstall.php` (factory-based), test bootstrap + affected tests.

**Unchanged:** `AbstractServiceProvider`, `ServiceProviderInterface`, `ActivatableInterface`, `Config/OptionBackedConfigRepository`, `ConfigRepositoryInterface`, `LoggerInterface`, `EventDispatcherInterface`, `StoppableEventInterface`, all of `RestApi/` and `Settings/`, `ModuleManifest`.

## Test coverage after 1.1

Unit tests: Container (incl. alias/tag/lazy/circular), Logger (incl. correlation/context/env suppression), EventDispatcher (incl. metadata), Config repository, PluginConfig, SettingsField. I could not execute them here (no network for `composer install`); validated structurally (brace balance, PSR-4 path match, import resolution). Run `composer test` locally before building a release.

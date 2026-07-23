# ADR-0001: Remove the Plugin Singleton

**Status:** Accepted · **Module:** 1.1

## Context

Module 1's original `Plugin` kernel used a lazily-created, statically-cached singleton (`Plugin::instance()`), matching a common WordPress-plugin pattern. `Activator`/`Deactivator`/`Uninstaller` each fetched the kernel this way.

## Decision

Remove the singleton entirely. `Plugin` takes its `Container` and provider manifest through the constructor. A new `PluginFactory::create()` is the single composition root, called once per WordPress entry point (activation, deactivation, `plugins_loaded`) inside its own closure — never cached globally.

## Consequences

- No shared mutable global state. Every test can construct its own isolated container with nothing to reset between tests.
- The *only* place `Plugin::instance()`-style static access existed is gone; every class receives dependencies through its own constructor, so a class's real dependencies are always visible in its signature.
- Building a kernel per entry point costs nothing extra: only one entry point fires per request, so there's no duplicate construction.
- The only two remaining global file-scope values are `ANA_PRO_VERSION` and `ANA_PRO_FILE` constants, read in exactly one place (`PluginConfig::fromPluginFile()`).

## Alternatives Considered

- **Keep the singleton, add a reset-for-testing method.** Rejected: still legitimizes service-location (`Plugin::instance()->container()->get()`) from anywhere, which is the exact anti-pattern DI is meant to prevent.

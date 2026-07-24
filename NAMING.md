# Naming & Architecture Terminology

The single source of truth for how this codebase names itself. Read this before writing any documentation or comments in future modules.

## The three layers

| Layer | Name | What it is |
|---|---|---|
| **Product** | **AI News Automator Pro** | The commercial WordPress plugin. This is the brand users see: plugin header, admin menu, marketing, support. |
| **Platform** | **AI Publishing Engine** | The generic, modular, domain-agnostic automated-publishing architecture that the product is built on. This is the internal/architectural name. |
| **Vertical** | e.g. **News** | A domain-specific layer on top of the engine (prompt templates, source presets, content-type rules). *News* is the first vertical and the one the product ships with. |

## Why the split

The engine does source discovery, research, fact-checking, generation, SEO, image processing, publishing, and analytics — none of which is inherently about "news." Naming the architecture the *AI Publishing Engine* makes explicit that the same foundation can power blogs, affiliate sites, documentation, product catalogs, and other publishing workflows by adding verticals, without touching engine code. The product keeps its established brand.

## How to refer to things

- In **architecture docs, design docs, module READMEs, and comments that describe the platform**: call it the **AI Publishing Engine** (or "the engine").
- When referring to **the shipping product / plugin as a whole**: call it **AI News Automator Pro** (or "the plugin").
- **News-specific** behavior: call it the **News vertical**.

## What did NOT change (backward-compatibility freeze)

This was a terminology-only change. The following are code/data identifiers and remain exactly as they were — renaming them would break existing installations (orphaned secrets, lost capabilities, dropped settings):

- PHP namespace: `AINewsAutomator\`
- Plugin slug and main file: `ai-news-automator-pro`
- WordPress plugin header name: `AI News Automator Pro`
- Text domain: `ai-news-automator`
- Data prefixes: `ana_` (capabilities), `ai_news_automator_` (options)
- Option names, meta keys, REST namespaces, hook names
- All class, interface, and method names
- `composer.json` PSR-4 map
- Migration identifiers

User-facing display strings (admin menu title, plugin header, log prefix, settings page titles, error notices) also remain product-branded as "AI News Automator", because those are product surfaces, not architecture descriptions.

## Where vertical code will live (convention for future modules)

Engine modules stay domain-agnostic under their existing namespaces (`AINewsAutomator\Core`, `AINewsAutomator\Security`, `AINewsAutomator\AI`, etc.). Vertical-specific code is intended to live under a dedicated sub-namespace — e.g. `AINewsAutomator\Verticals\News` — so the engine never depends on any vertical, and new verticals are pure additions. (The namespace keeps the `AINewsAutomator` root for backward compatibility even though it describes the *engine*; the root is a frozen identifier, not a description.)

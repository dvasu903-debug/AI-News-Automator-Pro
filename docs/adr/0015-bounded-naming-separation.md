# ADR-0015: Bounded Naming Separation — Engine vs. Product vs. Vertical

**Status:** Accepted · **Cross-cutting**

## Context

The architecture needed a name distinct from the commercial product, so the same foundation could plausibly power future publishing verticals beyond news (blogs, affiliate sites, documentation, product catalogs) without the architecture's own name implying it's news-specific.

## Decision

Three names, three layers, documented centrally in `NAMING.md`:
- **Product**: AI News Automator Pro (the WordPress plugin brand — plugin header, admin menu, user-facing strings).
- **Platform/Architecture**: AI Publishing Engine (internal/architectural name — docs, design records, module descriptions).
- **Vertical**: News (the first, currently the only, domain-specific layer on the engine).

Code identifiers (the `AINewsAutomator\` namespace, `ana_` prefixes, option/meta keys, REST namespaces, text domain, plugin slug) are **frozen** — this was an explicitly bounded, documentation-and-comments-only rename, never touching a single code identifier, verified file-by-file when it was made.

## Consequences

- Architecture documents and module READMEs consistently describe "the engine," while user-facing strings and the plugin header consistently say "AI News Automator Pro" — no mixed messaging in either direction.
- A future vertical (e.g. a blog-content vertical) has a natural home: `AINewsAutomator\Verticals\{VerticalName}`, under the same frozen namespace root, without implying the engine itself is news-specific.
- Any name proposed for the architecture that isn't "AI Publishing Engine" (e.g. an informal "AI Creator OS" mentioned in passing during Module 4's kickoff) is treated as informal phrasing, not a rename, unless a new bounded rename is explicitly requested and executed the same careful way.

## Alternatives Considered

- **Full rename including code identifiers.** Rejected: would break any already-installed site's stored data (encrypted secrets, capabilities, saved settings) for a purely cosmetic/architectural gain.
- **No separation — call everything "AI News Automator."** Rejected: makes it harder to reason about which parts of the codebase are domain-agnostic (reusable for a future vertical) versus News-specific, undermining the whole point of building an "engine."

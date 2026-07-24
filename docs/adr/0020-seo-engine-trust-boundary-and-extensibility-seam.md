# ADR-0020: SEO Engine Trust Boundary and Extensibility Seam

**Status:** Accepted · **Module:** 9

## Context

Module 8's own design and Milestone 4's own code comments both
anticipated "a future SEO module" owning `ana_draft_seo` more fully —
see `planning/MODULE_8_PUBLISHING_ENGINE_DESIGN.md` §11 and
`PostProcessAction`'s `canonical_url` comment. `planning/MODULE_9_SEO_ENGINE_DESIGN.md`
covers the full architecture review, scope, and design; this ADR
records the concrete decisions actually made during implementation.

Module 9 is architecturally different from every prior module in one
respect: it is the first module whose code runs on `wp_head` — a
public, anonymous-visitor-facing render path — rather than only
admin/REST/cron/queue contexts. That changes both the escaping
discipline (HTML/attribute/JSON contexts, not just "is this string
already WordPress-escaped somewhere") and the performance profile (this
code now runs on every front-end page view, not just an authenticated
action).

## Decision

**1. `MetaTagBuilder` constructs tag data; `SeoHeadRenderer` only
renders it.** Approved refinement to the original design (owner
request): splitting construction from rendering means the primary tag-
assembly logic is unit-testable without any WordPress hook or output-
buffer machinery, and `SeoHeadRenderer` — the module's one output/
escaping boundary — has nothing else to reason about.

**2. `SeoProviderInterface` is the extensibility seam; `DefaultSeoProvider`
is its only implementation.** Approved refinement to the original
design (owner request), for future vertical- or integration-specific
SEO behavior (a Google Discover provider, a News SEO provider, a
WooCommerce SEO provider — named as future examples, not built now). No
registry/discovery mechanism exists yet — `SeoProviderInterface::class`
is bound directly to `DefaultSeoProvider`, mirroring
`EditorialPolicyInterface`'s own single-implementation starting state
in Module 8 before `ResearchEditorialPolicy` existed. A second provider,
when genuinely needed, is a second bound implementation plus whatever
selection logic that need requires — not built speculatively now.

**3. Canonical URL is computed live via `get_permalink()`, never read
from the stored `ana_draft_seo.canonical_url` column.** That column is
left exactly as Milestone 4 left it (null, undisturbed) — Module 9 adds
no second writer to it. A stored snapshot goes stale the instant a
slug/permalink structure changes; `get_permalink()` is already
WordPress's own authoritative, always-current source, so trusting it
directly avoids an entire staleness-bug class for zero loss of
function.

**4. `wp_json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP)` — not
`JSON_UNESCAPED_SLASHES` — is the JSON-LD escaping mechanism.**
`JSON_HEX_TAG` converts every `<`/`>` character inside the encoded
payload into a `\u` escape sequence, which eliminates a `</script>`
tag-breakout risk regardless of what a title/description string
contains — a more robust guarantee than relying on default slash-
escaping alone (which is what the very first design draft proposed
before this was corrected during implementation; see "Alternatives
Considered"). `JSON_HEX_AMP` additionally neutralizes `&`-based entity
tricks. Never `esc_html()` on the JSON-LD payload — that double-encodes
and breaks the payload.

**5. Every `SeoTagData` field is re-escaped at `SeoHeadRenderer`'s own
output site, regardless of any upstream sanitization.**
`ana_draft_seo`'s fields were already sanitized once, inside
`AiContentGenerator` (Milestone 4), for the HTML-*body* context via
`wp_kses_post()`. That does not make them safe for an HTML *attribute*
(`esc_attr()`) or a *JSON string* (`JSON_HEX_TAG`/`JSON_HEX_AMP`)
context — a value's sanitization history never substitutes for
escaping at its actual destination context. This is ADR-0019's
trust-boundary discipline applied to a new kind of output.

**6. `InternalLinkSuggester` is admin-editor-only, deterministic, and
never calls `AIManager`.** Ranks other **published** posts by shared
extracted-entity count with the current post's linked research session
(`Research\Entities\ExtractedEntity`, read via the frozen
`SessionRepositoryInterface::summarize()`) — never invoked from the
public `wp_head` path, so its comparatively heavier per-call cost never
lands on the hot path every visitor hits. No AI call, for the same
reasoning ADR-0019 decision 6 gave for `PostProcessAction`: a second AI
call here would reopen an untrusted-output trust boundary with no
sanitization plan of its own.

**7. No new table.** `ana_draft_seo` (Publishing, Milestone 1, frozen)
already has every column this module needs. Module 9 depends on
Publishing's frozen `DraftSeoRepositoryInterface` (read-only in this
module — `upsert()` remains solely `PostProcessAction`'s to call) and
Research's frozen `SessionRepositoryInterface`/`ExtractedEntity` —
zero changes to any frozen Module 1–8 file.

**8. No human-editable override path in this first milestone.**
Read-only/render-only against `ana_draft_seo`. Deferred, matching this
project's "start narrow" precedent (Publishing's own Milestone 1 was
migrations-only). A follow-up milestone building an override UI would
need an additive migration (a per-field lock/override marker) so a
human edit isn't silently overwritten by a later `PostProcessAction`
re-run — noted for that future milestone, not decided now.

## Consequences

- Module 9's surface is: `SeoProviderInterface` + `DefaultSeoProvider`,
  `MetaTagBuilder`, `SchemaOrgGenerator`, `CanonicalUrlResolver`,
  `InternalLinkSuggesterInterface` + `InternalLinkSuggester`,
  `BreadcrumbGenerator`, `SeoHeadRenderer`, `SeoHealthCheck`, and
  `SeoServiceProvider` (ninth in `ModuleManifest`). No new table; no
  REST surface; no changes to any frozen Module 1–8 file beyond the two
  designated append points every prior module has also used
  (`ModuleManifest.php`'s provider list, `phpunit.xml.dist`'s testsuite
  list, `verify-runtime.sh`'s `FULL_SEQUENCE`).
- This is the first module whose static verification and runtime
  checklists needed a *new class* of test: an escaping-regression suite
  proving a hostile string never survives unescaped in HTML-attribute,
  URL, or JSON-LD-inside-`<script>` contexts — not just proving the
  happy path works.
- `tests/bootstrap.php`'s `wp_json_encode()` stub silently ignored the
  `$flags` parameter before this module's tests exercised it — found
  and fixed (now matches the real `wp_json_encode(mixed $data, int
  $flags = 0, int $depth = 512)` signature the runtime harness's own
  stub already had) because a real escaping-regression assertion
  actually exercised the gap, not because it was inspected code-by-code.
- The runtime harness's `get_permalink()` shim previously returned
  `false` for any post without an explicitly pre-seeded permalink,
  unlike real WordPress (which always returns a URL, e.g. the `?p=123`
  fallback, for any existing post). Found and fixed while validating
  the Hostinger smoke test script locally before hand-off; the fix adds
  a fallback only when no explicit value was configured, so no existing
  checklist's explicit-permalink assertions changed behavior.

## Alternatives Considered

- **`JSON_UNESCAPED_SLASHES` for JSON-LD encoding** — the original
  design draft's proposed flag. Rejected during implementation:
  slash-escaping alone (PHP's `json_encode()` default) does prevent a
  literal `</script>` substring from surviving, but `JSON_HEX_TAG` is
  the more direct, standard, and robust technique (it removes the `<`/
  `>` characters themselves via unicode escaping, rather than relying
  on an indirect consequence of slash-escaping), and is what this
  module actually ships with.
- **A registry/discovery mechanism for `SeoProviderInterface`
  (`tag()`/`tagged()`) built now, ahead of a second implementation
  existing.** Rejected: no second provider exists yet to select
  between; building selection machinery speculatively is the same
  anti-pattern ADR-0016 already rejected for Sources' scheduling/retry
  needs. A direct single binding is added when a genuine second
  provider needs it, exactly like `EditorialPolicyInterface`'s own
  history.
- **Storing a backfilled `canonical_url` in `ana_draft_seo` at
  publish-time.** Rejected: adds a second writer to a Milestone-4-frozen
  column for a value (`get_permalink()`) WordPress core already
  computes correctly and cheaply on demand — the stored copy would only
  ever be a staleness risk, never a genuine improvement, since nothing
  in this module reads that column for rendering.
- **Building a human-editable SEO override UI/REST endpoint in this
  milestone.** Rejected: no requirement forces it now, and building it
  means designing concurrent-write semantics (a human edit vs. a later
  automated `PostProcessAction` re-run) that don't need solving yet —
  deferred to a future milestone, per this project's "start narrow"
  discipline.

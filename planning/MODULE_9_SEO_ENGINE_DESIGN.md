# Module 9 — SEO Engine: Architecture Review & Design

Status: **planning document, no code.** Produced per the explicit
instruction to review the project's existing architecture and propose
the next module before writing anything. Mirrors the format
`ARCHITECTURE_PLAN.md` and `MODULE_8_PUBLISHING_ENGINE_DESIGN.md`
already established: audit first, design second, open questions listed
at the end, implementation waits for approval.

---

## Part 1 — Architecture review

### What was reviewed

- `ROADMAP.md`, `ARCHITECTURE_PLAN.md` (the original full audit/plan —
  its Module 9 sketch and folder listing), `NAMING.md`.
- All 19 ADRs (`docs/adr/0001`–`0019`).
- `planning/MODULE_8_PUBLISHING_ENGINE_DESIGN.md`,
  `planning/MODULES_6_7_8_DESIGN.md`, `planning/AI_PROVIDER_ENGINE_DESIGN.md`,
  `planning/STORAGE_DESIGN.md`, `planning/SECURITY_DESIGN.md`,
  `planning/SOURCE_CONNECTORS_ENGINE_DESIGN.md`.
- The actual `src/` tree for Modules 1–8 (frozen except Module 8, now
  through Milestone 4), and the deferred-items language in ADR-0018 and
  ADR-0019.

### Finding: the next module is already named, three times, in this project's own documents

This is not a proposal invented for this review — it is the literal,
repeated language already in the codebase:

1. **`ARCHITECTURE_PLAN.md`'s original module order** (§2.1, folder
   listing) puts `SEO/` immediately after the Pipeline/Publishing
   modules: *"SEO/Images/Publishing (9–11)"*, with Module 9 owning
   `SchemaGenerator`, `OpenGraphGenerator`, `TwitterCardGenerator`,
   `InternalLinkSuggester`, `BreadcrumbGenerator`.
2. **`MODULE_8_PUBLISHING_ENGINE_DESIGN.md` §11 ("Future
   extensibility")**, written before Milestone 1 of Module 8 existed:
   *"SEO module (Module 9?) can own `ana_draft_seo` more fully without
   Module 8 needing to change — table already structured for it."*
3. **This session's own Milestone 4 work** — the `ana_draft_seo`
   migration's docblock ("kept structured for the future SEO module")
   and `PostProcessAction`'s own code comment on `canonical_url`
   ("left null rather than guessed at, so a later SEO module can
   populate it deliberately") — both written during Milestone 4,
   independently landing on the same conclusion as the two documents
   above.

No other undone module is referenced this explicitly or this many
times. **Module 9 — SEO Engine is the logical next module.**

### What already exists for Module 9 to build on (no redesign needed)

- **`ana_draft_seo`** (Publishing, Milestone 1, frozen): `id, post_id
  (UNIQUE), meta_title, meta_description, focus_keyword, canonical_url,
  robots_directives, created_at, updated_at`. Every column Module 9
  needs already exists. **Zero new tables are required for the scope
  below.**
- **`Publishing\Contracts\DraftSeoRepositoryInterface`** (Milestone 4,
  frozen): `upsert()`, `findByPostId()`. Module 9 depends on this
  interface exactly the way Module 8 depends on `Research\DTO\ResearchSummary`
  and `Research\Contracts\SessionRepositoryInterface` — a downstream
  module consuming a frozen upstream contract, never its concrete class.
- **`Publishing\Events\PublishingCompletedEvent`** (Milestone 4,
  frozen) — dispatched when `PostProcessAction` finishes. Explicitly
  named as an "intended extension seam" in the Module 8 design doc's
  own §11.
- **`Research\Entities\ExtractedEntity`** (Module 6, frozen) — the
  natural data source for internal-link suggestions (shared entities
  between posts), if that dependency is approved (see Open Questions).
- **`Security\Request\InputValidator`** (Module 2, frozen) — for reading
  any admin-side override input, per its own documented split
  (sanitize input here, escape output at the render site with
  WordPress's own `esc_*` functions).
- **`AbstractRestController`/`RestSecurityMiddleware`/`CapabilityGateInterface`**
  (Modules 1–2, frozen) — the established REST/authorization pattern,
  if a REST surface is added (see Open Questions).

### Dependencies on completed modules

```
SEO (9)
  ├── depends on → Publishing (8): DraftSeoRepositoryInterface, PublishingCompletedEvent
  ├── depends on → Research (6): ExtractedEntity  [proposed — see Open Question 6]
  ├── depends on → Security (2): CapabilityGate, InputValidator, RestSecurityMiddleware  [if REST override is built]
  └── depends on → Core (1): Container, EventDispatcher, RestApiRegistry, HealthCheckInterface
```

Strictly downstream, matching Module 8's own dependency-graph
discipline — nothing in Modules 1–8 depends on Module 9, and no Module
1–8 file is modified by this design.

### A genuinely new architectural surface (why this isn't "just another module")

Every module built so far (1–8) only ever produces output for: an
admin screen, a REST JSON response, a WP-CLI/cron/queue context, or an
internal exception message. **None of them render into the public,
anonymous-visitor-facing page output.** Module 9 is the first time this
project's code runs on `wp_head` — a hook that fires on every public
page view, for every visitor, including logged-out ones. This has two
consequences addressed explicitly below (Security, Performance): the
escaping discipline changes (HTML/attribute/JSON contexts, not just
"is this a WordPress-escaped string somewhere"), and the performance
budget changes (this code now runs on every front-end hit, not just
admin/cron/queue actions).

### Architectural decisions that must be made before implementation

These are listed in full in **Open Questions**, at the end of this
document, per the instruction not to redesign frozen modules or assume
scope. Summary: (1) whether canonical URL is stored or computed live,
(2) whether a human-editable override path exists in this first
milestone or is deferred, (3) which theme hook is authoritative for
markup injection, (4) whether the Research dependency for internal-link
suggestions is approved, (5) module numbering confirmation (Module 9,
not "Publishing Milestone 5").

One standing cross-cutting item, noted so it isn't rediscovered: **ADR-0016/0017's
retry-extraction trigger does not apply here** — Module 9 makes no
outbound network calls and needs no retry/backoff logic of its own.

---

## Part 2 — Design

## Objectives

1. Turn the SEO metadata Milestone 4 already *collects* (`ana_draft_seo`)
   into SEO metadata that actually *renders* on the public page: a
   canonical link, Open Graph tags, Twitter Card tags, and schema.org
   JSON-LD structured data — closing the specific gap Milestone 4 left
   open by design.
2. Add the two remaining named components from the original
   architecture plan's Module 9 scope: deterministic internal-link
   suggestions and breadcrumb markup.
3. Do all of this without a new table, without modifying any frozen
   Module 1–8 file, and with the same trust-boundary discipline
   ADR-0019 established (escape at the point of output, never assume
   upstream sanitization crosses a context boundary).

## Scope

- `Seo\SeoServiceProvider` — the module's one service provider,
  following the exact `register()`/`boot()` split every prior module
  uses.
- `Seo\Contracts\SeoProviderInterface` — the future-extensibility seam
  the owner asked for (`supports()`/`provide()` — a vertical- or
  integration-specific provider decides whether it applies to a post,
  and returns the tag data if so). Exactly one implementation exists in
  this module: `Seo\Services\DefaultSeoProvider`, bound directly to the
  interface (no registry/discovery machinery, since there is only one
  registered implementation today — mirrors `EditorialPolicyInterface`'s
  own single-implementation starting state in Module 8 before
  `ResearchEditorialPolicy` existed). Future providers
  (`GoogleDiscoverSeoProvider`, `NewsSeoProvider`,
  `WooCommerceSeoProvider`, named by the owner as examples) are **not**
  built now — only the seam.
- `Seo\Services\MetaTagBuilder` — the one place that assembles a post's
  complete SEO tag data (canonical, robots, `og[]`, `twitter[]`,
  `jsonld`) into a single `Seo\DTO\SeoTagData` value object. Pure data
  transformation: reads `ana_draft_seo` + the `WP_Post` + an existing
  featured image if any; performs no escaping and no echoing itself.
  `DefaultSeoProvider` delegates to this class.
- `Seo\Frontend\SeoHeadRenderer` — hooks `wp_head` (priority early
  enough to run before most themes' own output), resolves
  `SeoProviderInterface::provide($postId)`, and is the **only** class
  in this module that echoes anything — every field is escaped at its
  own output context right here (see Security). For a singular post
  view of a post with an `ana_draft_seo` row, renders, in order: a
  `<link rel="canonical">`, a `<meta name="robots">` (from
  `robots_directives`), Open Graph tags (`og:title`, `og:description`,
  `og:type=article`, `og:url`, `og:image` if a featured image exists,
  `og:site_name`), Twitter Card tags (`twitter:card`, `twitter:title`,
  `twitter:description`, `twitter:image`), and one `<script
  type="application/ld+json">` block (schema.org `NewsArticle`).
  **No `ana_draft_seo` row → the provider returns null → renders
  nothing** (a manually-created, non-pipeline post is untouched —
  matches `ResearchEditorialPolicy`'s own "no linked data, pass
  trivially" precedent from Milestone 4). Splitting tag-*construction*
  (`MetaTagBuilder`, returns data) from tag-*rendering* (`SeoHeadRenderer`,
  echoes escaped strings) makes the construction logic fully unit
  -testable without any WordPress hook/output-buffer machinery.
- `Seo\Services\SchemaOrgGenerator` — builds the JSON-LD array
  (`headline`, `datePublished`, `dateModified`, `author`, `publisher`,
  `mainEntityOfPage`) from the post + `ana_draft_seo` row. Pure data
  transformation, no I/O beyond reading what's already loaded. Called
  by `MetaTagBuilder`, not directly by the renderer.
- `Seo\Services\CanonicalUrlResolver` — wraps `get_permalink()`. See
  Open Question 2 for why this computes live rather than trusting the
  stored `canonical_url` column for rendering. Called by
  `MetaTagBuilder`.
- `Seo\Services\InternalLinkSuggester` (+ `Contracts\InternalLinkSuggesterInterface`) —
  **admin-editor-only**, never on the public `wp_head` path (see
  Performance). Given a post's linked research session's
  `ResearchSummary` (or, for a manual post, its title/category),
  suggests other **published** posts sharing extracted entities,
  ranked by shared-entity count and recency. Deterministic string/set
  matching only — no `AIManager` call, matching ADR-0019 decision 6's
  reasoning exactly (a second AI call here would reopen the same
  untrusted-output trust boundary with no sanitization plan of its
  own).
- `Seo\Services\BreadcrumbGenerator` + a template-tag function
  (`ana_seo_breadcrumbs()`) themes can call, matching how established
  WordPress SEO plugins expose breadcrumbs — Home → category/vertical →
  post title, from WordPress's own taxonomy data, not invented.
- `Seo\Health\SeoHealthCheck` — matches every prior module's health
  check convention (`PublishingHealthCheck`, `AIProviderHealthCheck`,
  etc.).

## Out of scope (explicitly, so it isn't assumed later)

- **XML sitemaps.** WordPress core (5.5+) already ships a native XML
  sitemap; duplicating it would conflict with core behavior. Not part
  of the original architecture plan's Module 9 scope either.
- **Image generation/optimization/thumbnails** (`og:image` sourcing
  beyond referencing an *existing* featured image) — that is Module
  10 (Images) per `ARCHITECTURE_PLAN.md`'s own numbering. Module 9 only
  reads `get_the_post_thumbnail_url()` if a featured image already
  exists; it does not source, generate, or resize one.
- **Social sharing connectors** (Module 12) and **Analytics** (Module
  13) — explicitly deferred in both ADR-0019 and the Module 8 design
  doc's §11.
- **A second AI call for higher-quality meta copy or link suggestions.**
  Rejected for the same reason ADR-0019 decision 6 rejected it for SEO
  metadata inside `PostProcessAction`.
- **Redirect management / 404 monitoring / AMP / rich-results testing
  integration.** Not named anywhere in this project's design docs —
  no invented scope.
- **A human-editable override UI/REST endpoint for `ana_draft_seo`
  fields**, in this first milestone — see Open Question 3.

## Components

```
src/Seo/
├── Contracts/
│   ├── SeoProviderInterface.php          # future-extensibility seam (owner-requested)
│   └── InternalLinkSuggesterInterface.php
├── DTO/
│   └── SeoTagData.php                    # canonical, robots, og[], twitter[], jsonld — no behavior
├── Services/
│   ├── DefaultSeoProvider.php            # implements SeoProviderInterface, delegates to MetaTagBuilder
│   ├── MetaTagBuilder.php                # assembles SeoTagData; no escaping, no echoing
│   ├── SchemaOrgGenerator.php
│   ├── CanonicalUrlResolver.php
│   ├── InternalLinkSuggester.php
│   └── BreadcrumbGenerator.php
├── Frontend/
│   └── SeoHeadRenderer.php               # the only class that echoes anything
├── Health/
│   └── SeoHealthCheck.php
└── SeoServiceProvider.php
```

No `Repositories/` directory — Module 9 reads `ana_draft_seo`
exclusively through Publishing's already-frozen
`DraftSeoRepositoryInterface`, never a new repository of its own.

## Class diagram (dependency shape, not full UML — matches this project's existing ASCII-diagram convention)

```
SeoServiceProvider
  registers:
    SeoProviderInterface -> DefaultSeoProvider          # the extensibility seam
    InternalLinkSuggesterInterface -> InternalLinkSuggester
    MetaTagBuilder, SchemaOrgGenerator, CanonicalUrlResolver, BreadcrumbGenerator,
    SeoHeadRenderer, SeoHealthCheck
  boot():
    add_action('wp_head', [SeoHeadRenderer, 'render'])

SeoHeadRenderer
  └── depends on → SeoProviderInterface (this module — resolves to DefaultSeoProvider today)
      escapes every SeoTagData field at the point of echo — esc_url() /
      esc_attr() / wp_json_encode(..., JSON_HEX_TAG | JSON_HEX_AMP) —
      never trusts any upstream sanitization to already be output-context-safe.
      Contains NO tag-construction logic of its own — purely a renderer.

SeoProviderInterface (Contracts)
  └── implemented by → DefaultSeoProvider
        └── depends on → MetaTagBuilder (this module)
      Future (not built now): GoogleDiscoverSeoProvider, NewsSeoProvider,
      WooCommerceSeoProvider — each a second SeoProviderInterface
      implementation, resolved the same way ResearchEditorialPolicy is a
      second EditorialPolicyInterface implementation in Module 8.

MetaTagBuilder
  ├── depends on → DraftSeoRepositoryInterface (Publishing, frozen)
  ├── depends on → SchemaOrgGenerator (this module)
  └── depends on → CanonicalUrlResolver (this module)
      returns SeoTagData|null; no escaping, no echoing, no WordPress hooks
      — this is the class the escaping-regression unit tests target directly.

SchemaOrgGenerator
  └── depends on → DraftSeoRepositoryInterface (Publishing, frozen)
      returns plain array; only ever encoded by SeoHeadRenderer.

InternalLinkSuggester implements InternalLinkSuggesterInterface
  ├── depends on → SessionRepositoryInterface (Research, frozen)   [Open Question 6]
  └── depends on → ArticleRepositoryInterface (Storage, frozen) — published posts only
      admin-editor-only; never invoked from SeoHeadRenderer's wp_head path.

BreadcrumbGenerator
  └── depends on → WordPress core taxonomy functions only (no plugin dependency)
```

## Data flow

```
Public post request
  → WordPress core loads the post, fires wp_head
  → SeoHeadRenderer::render() (this module, new)
      → SeoProviderInterface::provide($postId)
          → DefaultSeoProvider → MetaTagBuilder::build($postId)
              → DraftSeoRepositoryInterface::findByPostId($postId)  [Publishing, frozen — read only]
                  ├─ null  → MetaTagBuilder returns null
                  └─ found → CanonicalUrlResolver::resolve($postId)  (live get_permalink())
                            → SchemaOrgGenerator::generate($post, $seoRow) → array
                            → assembled into one SeoTagData value object
      ├─ null       → render nothing, defer entirely to the theme/WordPress core
      └─ SeoTagData → echo, each field escaped at its own output context

Admin editor screen (draft/post editing)
  → InternalLinkSuggester::suggestFor($postId)   [never touches wp_head]
      → SessionRepositoryInterface::summarize($sessionId) if linked   [Research, frozen]
      → ArticleRepositoryInterface::pendingReview()/publish-status query [Storage, frozen]
      → ranked list of {postId, title, sharedEntityCount}, deterministic, no AI call
```

No write path exists in this design (see Out of Scope /
Open Question 3) — Module 9's first milestone is read-only against
`ana_draft_seo`.

## Security considerations

- **First module rendering into public, anonymous-visitor page output.**
  Every value echoed by `SeoHeadRenderer` must be escaped for its exact
  output context: `esc_url()` for `og:url`/canonical/image URLs,
  `esc_attr()` for any attribute value, and `wp_json_encode(...,
  JSON_HEX_TAG | JSON_HEX_AMP)` for the JSON-LD block (never
  `esc_html()` on JSON — that double-encodes and breaks the payload).
  The real risk in a JSON-LD `<script>` block is a literal `</script>`
  substring inside a string value breaking out of the tag —
  `JSON_HEX_TAG` converts every `<`/`>` to a `\u` escape, which
  eliminates that risk regardless of what a title/description string
  contains (a more robust guarantee than relying on slash-escaping
  alone); `JSON_HEX_AMP` additionally neutralizes `&`-based entity
  tricks. This exact interaction is a required unit-test case, not an
  assumption.
- `ana_draft_seo`'s fields were already sanitized once, for a
  **different context** (HTML body, via `wp_kses_post()` inside
  `AiContentGenerator`, Milestone 4). That does not make them safe for
  an HTML *attribute* or a *JSON string* context. Module 9 re-escapes
  at its own output site unconditionally — never trusts a value's
  history, only its destination context. This is a direct, concrete
  extension of ADR-0019's trust-boundary discipline into a new kind of
  output.
- `InternalLinkSuggester` must only ever suggest **published**-status
  posts — never leak a draft/private post's existence or title into a
  suggestion list a lower-privileged editor might see.
- If a REST override surface is ever added (deferred, see Open
  Question 3): reuse `RestSecurityMiddleware`/`CapabilityGateInterface`
  exactly as `PublishingController` does, with a new ability mapped to
  an **existing** `Capabilities` constant — per ADR-0018's established
  precedent, never a new raw constant added directly to the frozen
  `Capabilities` class.

## Performance considerations

- `wp_head` fires on **every public page view**, including anonymous
  visitors — a materially different performance profile from every
  prior module, which only ran on admin/REST/cron/queue paths. The
  `ana_draft_seo` lookup is a single indexed query (`UNIQUE post_id`
  already exists on the table) — cheap, but it is now on the hot path
  for the first time in this project, and should be considered for
  WordPress object-cache wrapping (`wp_cache_get`/`wp_cache_set` keyed
  by post ID) if profiling later shows it matters — not built
  preemptively, per this project's established anti-speculative-
  optimization discipline (ADR-0011's own "not building this
  preemptively" precedent for a compiled container).
- `InternalLinkSuggester`'s entity-matching work is explicitly
  **admin-only**, never reachable from the public `wp_head` path — a
  hard design constraint, not just an intention, to keep the two cost
  profiles (cheap-and-universal vs. can-be-heavier-but-rare) separate.
- JSON-LD/OG generation is pure string/array building over data already
  fetched in one query — no N+1 risk if implemented as designed (one
  `findByPostId()` call per render, not per field).

## Test strategy

- Unit tests (fakes, no WordPress integration — matches every prior
  module's PHPUnit convention): `SchemaOrgGeneratorTest`,
  `CanonicalUrlResolverTest`, `MetaTagBuilderTest` (the primary target —
  asserts the assembled `SeoTagData` shape for both a present and an
  absent `ana_draft_seo` row), `DefaultSeoProviderTest` (thin —
  delegates to `MetaTagBuilder`), `InternalLinkSuggesterTest`
  (deterministic ranking, asserts no AI dependency is even injected),
  `BreadcrumbGeneratorTest`.
- A dedicated **escaping-regression test class** for `SeoHeadRenderer`:
  seed `ana_draft_seo` with a deliberately hostile string (e.g. `"><script>alert(1)</script>` in `meta_title`, and a literal
  `</script>` substring specifically for the JSON-LD case) and assert
  it never appears unescaped in rendered output, in every context
  (HTML, attribute, JSON-LD). This is the same "prove the trust
  boundary holds, don't just reason about it" discipline
  `AiContentGeneratorTest` established in Milestone 4. Because
  `MetaTagBuilder` returns plain data and `SeoHeadRenderer` only
  renders it, this test exercises the real renderer against
  `MetaTagBuilder`'s real output — not a mock of the escaping logic
  itself.
- No dedicated unit test for the `wp_head` hook registration itself or
  `SeoHealthCheck` — matches this codebase's own established, stated
  precedent (REST controllers and health checks have no dedicated unit
  tests anywhere in this project; covered by runtime passes instead).

## Runtime verification plan

- Extend `scripts/runtime-harness/harness-bootstrap.php` with the
  first-ever shims for `get_permalink()`, `get_the_post_thumbnail_url()`,
  and a capturable `wp_head` action (assert-by-output-buffer, the same
  technique `boot-check.php` already uses for idempotency checks).
- New `scripts/runtime-harness/checklists/module9.php` (naming to match
  the existing `milestone2/3/4` convention — see Open Question 5 on
  whether this is "Module 9" or a Publishing milestone) verifying, all
  execution-first against real MariaDB + real WordPress core wpdb/dbDelta
  and the real production boot path: a real `ana_draft_seo` row (via
  the real, frozen `DraftSeoRepositoryInterface`) renders correct
  canonical/OG/JSON-LD; a post with no `ana_draft_seo` row renders
  nothing; the hostile-string regression case holds against the actual
  `SeoHeadRenderer`/`MetaTagBuilder`/`SchemaOrgGenerator` code paths
  running end-to-end through the real, booted container — the same
  discipline as every other checklist in this harness (only `wpdb`/
  `dbDelta` are fetched-verbatim WordPress core; the peripheral escaping
  functions are this harness's own shims, same as in unit tests — the
  proof this checklist adds is that the module's *own* code paths
  produce correct output end-to-end, not a claim about testing
  WordPress core's escaping implementation itself).
- Append the new checklist name to `verify-runtime.sh`'s
  `FULL_SEQUENCE` list (the process improvement just adopted in
  Milestone 4) so it becomes a permanent regression check going
  forward.

## Hostinger smoke-test plan

- No cost, no live AI call — this module makes no `AIManager` calls at
  all, so there is no cost-gating question this time (unlike Milestone
  4's `GenerateAction`).
- Scoped script (WP-CLI `eval-file`, following Milestone 4's now-fixed
  pattern — no `declare(strict_types=1)`) verifying: `SeoServiceProvider`
  loads, `SeoHealthCheck` resolves and runs, and a **genuinely new
  smoke-test dimension**: an actual anonymous HTTP `curl` of a real,
  temporarily-created test post's live URL, grepping the response body
  for `<link rel="canonical"`, `property="og:title"`, and
  `application/ld+json` — the first Hostinger smoke test that verifies
  public-facing rendered output rather than only admin/REST/CLI-side
  behavior. Test post and its `ana_draft_seo` row deleted afterward,
  matching Milestone 4's cleanup discipline.

## Acceptance criteria

1. `SeoServiceProvider` registers and boots with zero changes to any
   frozen Module 1–8 file.
2. A post with an `ana_draft_seo` row renders a correct, fully-escaped
   canonical link, robots meta tag, Open Graph tags, Twitter Card tags,
   and one valid JSON-LD `NewsArticle` block on its public page.
3. A post with **no** `ana_draft_seo` row renders none of the above —
   no fatal, no partial/empty tags.
4. The hostile-string escaping regression test passes in unit tests,
   the local real-database harness, and the Hostinger smoke test.
5. `InternalLinkSuggester` never surfaces a non-published post and
   never calls `AIManager`.
6. `php -l`, PHPUnit, and PHPCS are clean on every new file (matching
   the standard every prior milestone has held to).
7. `./scripts/verify-runtime.sh full` passes with the new checklist
   appended to `FULL_SEQUENCE`.
8. A live Hostinger smoke test confirms real, publicly-fetchable pages
   render the expected tags.

---

## Decisions (approved by the owner, recorded here — implementation follows this document as amended)

All six open questions below are **approved as recommended**, plus two
additional refinements the owner requested before implementation:

- **`MetaTagBuilder`** is introduced as its own service — `SeoHeadRenderer`
  is responsible for rendering only, never tag construction. Reflected
  above in Components/Class diagram/Data flow/Test strategy.
- **`SeoProviderInterface`** is introduced now (empty of logic — one
  method, one implementation) as the future-extensibility seam for
  vertical/integration-specific SEO behavior (Google Discover, News SEO,
  WooCommerce SEO — named as future examples, not built now). Only
  `DefaultSeoProvider` is implemented in this module. Reflected above.
- No other scope expansion. No changes to any frozen Module 1–8 file.

The original open questions, kept below for the record of what was
asked and approved:

1. **Module numbering.** Confirm this becomes **Module 9** (a new,
   separate module — matching how `ARCHITECTURE_PLAN.md`,
   `MODULE_8_PUBLISHING_ENGINE_DESIGN.md`, and this session's own
   Milestone 4 comments all independently phrase it), not a further
   Milestone of Module 8 Publishing. Recommendation: Module 9 — SEO
   rendering is a distinct concern (public output) from Publishing's
   (draft state transitions), even though it consumes Publishing's data.
2. **Canonical URL: live-computed only, or also backfilled into the
   stored column?** Recommendation: compute live via `get_permalink()`
   only for rendering in this first milestone; leave the stored
   `ana_draft_seo.canonical_url` column exactly as Milestone 4 left it
   (null, undisturbed) rather than adding a second writer to it. Avoids
   a staleness bug class (a stored snapshot going stale after a slug
   edit) for zero loss of function, since nothing currently reads that
   column for any purpose other than potential future export/API use.
3. **Human-editable override path for `ana_draft_seo` fields — build
   now or defer?** Recommendation: **defer**. This first milestone is
   read-only/render-only, no new write path, no new migration —
   smaller and safer, consistent with this project's own "start narrow"
   precedent (Milestone 1 of Publishing was migrations-only). If
   approved, a follow-up milestone would need an additive migration
   (a nullable "locked fields" marker or per-field override flag, not a
   column removal/change) so a human edit isn't silently overwritten by
   a later `PostProcessAction` re-run — flagging this now so it isn't
   forgotten, not deciding it now.
4. **Which theme hook is authoritative for markup injection?**
   Recommendation: `wp_head` only, for the canonical/OG/Twitter/JSON-LD
   tags described above. Overriding the actual `<title>` tag itself
   (via core's `document_title_parts` filter) is a separate, smaller
   decision not included in this scope unless you want it added.
5. **Approve the new SEO → Research dependency** (`InternalLinkSuggester`
   reading `Research\Entities\ExtractedEntity`/`SessionRepositoryInterface`)?
   Recommendation: approve — `ExtractedEntity` is Research's own frozen,
   public contract, and Module 8 already established the precedent of a
   later module depending on Research's DTOs the same way.
6. Confirm the **Out of Scope** list (no sitemaps, no image
   generation, no social/analytics, no second AI call, no override UI
   in this milestone) matches your intent before implementation begins.

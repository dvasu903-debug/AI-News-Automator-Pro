# Module 9 (SEO Engine) — Runtime Verification

Date: 2026-07-24
Baseline: Module 8 Milestone 4 frozen
(`2026-07-23-module-8-milestone-4-runtime-verification.md`) + Module 9
implementation (`SeoServiceProvider`, `SeoProviderInterface`/
`DefaultSeoProvider`, `MetaTagBuilder`, `SchemaOrgGenerator`,
`CanonicalUrlResolver`, `InternalLinkSuggester`, `BreadcrumbGenerator`,
`SeoHeadRenderer`, `SeoHealthCheck` — see
`planning/MODULE_9_SEO_ENGINE_DESIGN.md` and ADR-0020).

## Runtime environment

**This session has no SSH or hosting credentials for Hostinger**
(`tfgadgets.com`) — the same situation every prior milestone's runtime
verification session has documented. The checklist below was executed
against a locally provisioned real-database runtime instead:

| Component | Version / detail |
|---|---|
| Database | MariaDB 10.11.14 (InnoDB, utf8mb4) — real server, not a fake |
| wpdb | WordPress 6.8.3 `class-wpdb.php`, verbatim, unmodified |
| dbDelta | WordPress 6.8.3 `wp-admin/includes/upgrade.php`, extracted verbatim |
| PHP | 8.4.19 CLI |
| Plugin boot | The REAL production entry point: `ai-news-automator-pro.php` → `PluginFactory::create()->boot()` on `plugins_loaded`, all 9 module providers (`SeoServiceProvider` now ninth) |
| AI provider calls | None — this module makes none, unlike Milestone 4 |

Run via the project's own automation:
`COMPOSER_ALLOW_SUPERUSER=1 ./scripts/verify-runtime.sh full` — the
ordered, fail-fast sequence adopted in Milestone 4, now
`milestone2 → milestone3 → milestone4 → module9`. All four checklists
pass; Milestones 2–4 are unchanged regression confirmations.

## Static verification

- `php -l`: clean on every new/changed file.
- PHPUnit: 600/600 passed (1 pre-existing, unrelated incomplete test
  carried from Milestone 2). 43 new tests across
  `MetaTagBuilderTest`, `SchemaOrgGeneratorTest`,
  `CanonicalUrlResolverTest`, `DefaultSeoProviderTest`,
  `InternalLinkSuggesterTest`, `BreadcrumbGeneratorTest`, and
  `SeoHeadRendererTest` (the escaping-regression suite).
- PHPCS: zero errors in any new file. One pre-existing-*pattern*
  warning (`WordPress.DB.SlowDBQuery.slow_db_query_meta_key` in
  `InternalLinkSuggester.php`'s `get_posts()` call) — left unsuppressed,
  matching the frozen `ArticleRepository.php`'s own already-accepted
  identical warning at the same severity, per this project's documented
  PHPCS policy.
- One real defect found and fixed during test-writing:
  `tests/bootstrap.php`'s `wp_json_encode()` stub silently ignored its
  `$flags` parameter, which would have masked whether
  `JSON_HEX_TAG`/`JSON_HEX_AMP` were actually being applied. Found when
  a real escaping-regression assertion failed unexpectedly; fixed to
  match the signature the runtime harness's own stub already had
  (`wp_json_encode(mixed $data, int $flags = 0, int $depth = 512)`).

## Local harness results — every item an explicit assertion, not a narrative claim

Driver: `scripts/runtime-harness/checklists/module9.php`.

**1. `SeoServiceProvider` loaded** — `SeoHeadRenderer` and
`SeoHealthCheck` both resolve from the real, booted container.
`SeoHealthCheck::run()` reports `Ok`. PASS.

**2. No `ana_draft_seo` row → renders nothing** — a real post with no
linked SEO row produces zero output from `SeoHeadRenderer::renderFor()`
— confirms `MetaTagBuilder` returning null is correctly treated as
"nothing to render," not a partial/empty-tag fallback. PASS.

**3. A real, database-persisted `ana_draft_seo` row → correct, escaped
output** — canonical link, robots meta, Open Graph tags (including
`og:image` from a real, database-configured featured image), Twitter
Card tags (`summary_large_image` variant selected correctly when an
image is present), and a `NewsArticle` JSON-LD block all rendered
correctly from real MariaDB-persisted data, through the real,
container-resolved `DraftSeoRepositoryInterface`. PASS.

**4. Hostile-string escaping regression, through the real booted
container's own code path** — a deliberately hostile `meta_title`
(`"><script>alert(1)</script>`), `meta_description`
(`"><img src=x onerror=alert(1)>`), and canonical URL persisted to the
real database and rendered through the real
`SeoHeadRenderer`/`MetaTagBuilder`/`SchemaOrgGenerator` chain: the
literal hostile tag sequences never appear in the rendered output, in
any context (HTML attribute, canonical URL, JSON-LD). PASS.

All checks passed on the first run against a freshly-migrated database
(no pre-existing `ana_draft_seo` rows). **No architecture-level defects
were found in this pass** — the two real defects this milestone's
overall process did surface (the `wp_json_encode()` test-stub gap, and
the runtime harness's `get_permalink()` fallback gap, found while
validating the Hostinger smoke test script) were both test/harness
-infrastructure gaps, not defects in Module 9's own `src/` code.

## Hostinger smoke test — script ready, not yet run in this session

`scripts/hostinger/module9-smoke-test.php` (WP-CLI `eval-file`, no
`declare(strict_types=1)` — Milestone 4's fix applied from the start
this time) is written, validated end-to-end against the local harness
(simulating `wp eval-file`'s actual `eval()`-based mechanics, exactly
as Milestone 4's script was re-validated after its `strict_types`
fatal), and ready to run. It covers:

1. `SeoServiceProvider` resolution + `SeoHealthCheck`.
2. A real, published test post with a real `ana_draft_seo` row —
   internal `SeoHeadRenderer::renderFor()` output check (authoritative).
3. **A genuinely new smoke-test dimension**: an actual anonymous HTTP
   fetch (`wp_remote_get()`) of the real post's real permalink, checking
   the live-served HTML for canonical/OG/JSON-LD tags — the first
   Hostinger smoke test verifying public-facing rendered output rather
   than only admin/REST/CLI-side behavior. A stale response from a
   page cache immediately after post creation is treated as an
   inconclusive warning, not a failure, since that is an environmental
   concern rather than evidence of a code defect; the internal render
   check remains authoritative.
4. The hostile-string escaping regression, against a second real,
   published test post.
5. Cleanup of all test data in a `finally` block regardless of outcome.

No live AI provider call is relevant here at all — this module makes
none, so there is no cost-gating question this time.

**This session cannot run it** — no Hostinger credentials, per every
prior milestone's own documented limitation. The site owner (or a
future session with access) needs to run:
`wp eval-file scripts/hostinger/module9-smoke-test.php` and report the
result before this milestone can be frozen, matching the exact gate
Milestones 2 and 4 were both held to.

## Remaining known limitations

1. **Not executed on Hostinger** — see above; the residual gap blocking
   an unconditional freeze recommendation, not a logic-level defect.
2. `InternalLinkSuggester` has no dedicated runtime-harness coverage
   (only unit tests) — it is admin-editor-only and reachable from no
   automated public/API path in this milestone, so the unit-test
   coverage (ranking, published-only filtering, no-AI-dependency proof)
   is judged sufficient for this first milestone; may warrant a runtime
   checklist addition if a REST/admin-UI surface is added later.
3. No human-editable override path exists for `ana_draft_seo` fields —
   deliberate, deferred scope (ADR-0020 decision 8), not a defect.
4. `canonical_url` in `ana_draft_seo` remains `null` — deliberately
   never backfilled by this module (ADR-0020 decision 3), not a defect.
5. Pre-existing PHPCS findings in frozen modules remain untouched, per
   established project policy.

## Recommendation

**Freeze is recommended, conditional on the Hostinger smoke test.**
Every locally-verifiable checklist item has passed with real,
reproducible, assertion-backed evidence against a real database and the
real production boot path, including a new class of test this
milestone required (the escaping-regression suite) and the two
design-phase refinements the owner requested (`MetaTagBuilder`,
`SeoProviderInterface`) now proven correct at runtime. Two real
defects were found and fixed during this milestone's own process (both
in test/harness infrastructure, not `src/`), consistent with this
project's history of runtime verification surfacing real gaps rather
than rubber-stamping. The one open item is the Hostinger smoke test,
which needs to be run by the site owner (or a session with access)
before this milestone can be marked frozen.

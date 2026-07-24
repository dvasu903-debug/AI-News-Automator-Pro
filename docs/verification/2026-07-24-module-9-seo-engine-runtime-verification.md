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

**This session has no SSH or hosting credentials for Hostinger** — the
same situation every prior milestone's runtime verification session has
documented. The checklist below was executed against a locally
provisioned real-database runtime instead; the live Hostinger smoke test
(see below) was run separately by the site owner directly on the
`autocutai.in` production deployment:

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

## Hostinger smoke test — executed, PASSED

Run by the site owner on `autocutai.in` via
`wp eval-file scripts/hostinger/module9-smoke-test.php`. All ten
assertions passed:

```
[PASS] 1a: SeoHeadRenderer resolves
[PASS] 1b: SeoHealthCheck resolves and runs
[PASS] 2a: real post created and published
[PASS] 3a: canonical link rendered
[PASS] 3b: og:title rendered
[PASS] 3c: JSON-LD NewsArticle block rendered
[PASS] 4a: real permalink resolves
[PASS] 4b: live fetched page contains canonical/OG/JSON-LD tags
[PASS] 5a: hostile og:title never appears as a literal script tag
[PASS] 5b: hostile description never appears as a literal img/onerror tag
Success: MODULE 9 HOSTINGER SMOKE TEST PASSED
```

Notably, 4b (the new public-HTTP-fetch dimension — an actual anonymous
`wp_remote_get()` against the real permalink, checking the live-served
HTML) passed cleanly on the first attempt rather than landing in the
documented WARN/inconclusive path — no page-cache staleness was
observed. This is the first Hostinger smoke test in this project to
verify public-facing rendered output, not just admin/REST/CLI-side
behavior.

**One real deployment defect was found and fixed during this pass** —
not in `src/`, but in the deployment/activation state of the site
itself. The smoke test first failed with
`StorageException: Insert into "wp_ana_draft_seo" failed: Table
'...' doesn't exist`. Investigation (`wp db query` against
`ana_schema_migrations`) showed the site's recorded migrations topped
out at `20260715400004` (Workflow module) — meaning this site's only
real WordPress activation transition happened before Milestone 4/Module
9's later migrations existed in the codebase. A prior directory swap
(replacing a stale, non-git checkout with a fresh `git clone`) left the
plugin's active flag in `wp_options` unchanged, so `wp plugin activate`
was a no-op that never re-fired `register_activation_hook()`; the
self-healing `plugins_loaded` check only covers migrations added after
a site's last real activation, not this case. Fix: a genuine
deactivate→activate cycle
(`wp plugin deactivate ai-news-automator-pro && wp plugin activate
ai-news-automator-pro`), which triggered `Activator::activate()`'s
unconditional `MigrationRunner::migrate()` call, created
`wp_ana_draft_seo` (and confirmed no other migrations were silently
missing), and the smoke test then passed end-to-end. Documented as a
permanent operational note, not an ADR (no architectural decision
changed): `docs/DEPLOYMENT.md`.

No live AI provider call was relevant here — this module makes none, so
there was no cost-gating question this time.

## Remaining known limitations

1. `InternalLinkSuggester` has no dedicated runtime-harness coverage
   (only unit tests) — it is admin-editor-only and reachable from no
   automated public/API path in this milestone, so the unit-test
   coverage (ranking, published-only filtering, no-AI-dependency proof)
   is judged sufficient for this first milestone; may warrant a runtime
   checklist addition if a REST/admin-UI surface is added later.
2. No human-editable override path exists for `ana_draft_seo` fields —
   deliberate, deferred scope (ADR-0020 decision 8), not a defect.
3. `canonical_url` in `ana_draft_seo` remains `null` — deliberately
   never backfilled by this module (ADR-0020 decision 3), not a defect.
4. Pre-existing PHPCS findings in frozen modules remain untouched, per
   established project policy.

## Recommendation

**Module 9 is frozen.** Every locally-verifiable checklist item and the
live Hostinger smoke test have both passed with real, reproducible,
assertion-backed evidence against a real database and the real
production boot path, including a new class of test this milestone
required (the escaping-regression suite) and the two design-phase
refinements the owner requested (`MetaTagBuilder`,
`SeoProviderInterface`) now proven correct at runtime, on the real
production stack, over real public HTTP. Three real defects were found
and fixed during this milestone's own process: two in test/harness
infrastructure (`wp_json_encode()`'s flags stub, the harness's
`get_permalink()` fallback), not `src/`; one in the live site's
deployment/activation state, not `src/` either (see `docs/DEPLOYMENT.md`)
— consistent with this project's history of runtime verification
surfacing real gaps rather than rubber-stamping. No open items remain.

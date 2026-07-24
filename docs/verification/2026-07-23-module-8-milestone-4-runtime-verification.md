# Module 8 (Publishing Engine) — Milestone 4 Runtime Verification & Freeze Report

Date: 2026-07-23 – 2026-07-24
Baseline: Milestone 3 frozen (`2026-07-23-module-8-milestone-3-runtime-verification.md`)
+ Milestone 4 implementation (`GenerateAction`, `ValidateContentAction`,
`PostProcessAction`, `AiContentGenerator`, `ResearchEditorialPolicy`,
`DraftSeoRepository` — see ADR-0019).

## Runtime environment

Two environments were used, for two different purposes — same pattern
as Milestones 2 and 3:

| Purpose | Environment |
|---|---|
| Full checklist (action registration, `AIManager` orchestration, trust boundary, policy merging, `priorOutput()`, SEO persistence) | Locally-provisioned MariaDB 10.11.14 (InnoDB, utf8mb4) + verbatim WordPress 6.8.3 `wpdb`/`dbDelta`, PHP 8.4.19 CLI, plugin booted through its real production entry point (`ai-news-automator-pro.php` → `PluginFactory::create()->boot()`) |
| Hostinger smoke test (this report's subject) | **tfgadgets.com on Hostinger** — PHP 8.3.30, WP-CLI 2.12.0, real production MySQL/MariaDB, the plugin's actual deployed code at commit `599582b` |

Local pass run via the project's committed automation:
`COMPOSER_ALLOW_SUPERUSER=1 ./scripts/verify-runtime.sh full` (the new
fail-fast, ordered `milestone2 → milestone3 → milestone4` sequence added
this milestone — see "Process improvement" below) — Milestone 2 and 3's
checklists re-ran as regressions and both still pass unchanged.

In both environments, the AI provider call is a test double: real
`AIManager` orchestration (validation, caching, rate limiting, retry/
failover, cost calculation, event dispatch, request/metrics recording)
runs against a fake `ChatProviderInterface` registered into the real
`ProviderRegistryInterface` the same way `AIServiceProvider` registers
its real providers — **only the network call is faked**; no real AI
provider call was made anywhere in this milestone's verification (no
API key configured, no cost incurred), per the owner's explicit
instruction that a live call is optional and cost-gated.

## Static verification (all new/changed Milestone 4 files)

- `php -l`: clean on every new/changed file.
- PHPUnit: 557/557 passed (1 pre-existing, unrelated incomplete test
  carried from Milestone 2 — `FakeWpdb`'s `!=` operator gap). 68 new
  assertions-worth of coverage added across `AiContentGeneratorTest`,
  `ResearchEditorialPolicyTest`, `DraftSeoRepositoryTest`,
  `GenerateActionTest`, `ValidateContentActionTest`,
  `PostProcessActionTest`.
- PHPCS: zero findings in any new or changed Milestone 4 file. (Two
  `WordPress.Security.EscapeOutput.ExceptionNotEscaped` false positives
  were found and suppressed with the same documented
  `phpcs:ignore` pattern already established in `WorkflowRunner.php` —
  the flagged values are internal exception-message arguments, never
  echoed as HTML.) Pre-existing baseline findings in frozen files
  elsewhere in the tree are unchanged, per this project's documented
  PHPCS policy (exit 1 = reviewed baseline debt, not a blocker).

## Local harness results — every item an explicit assertion, not a narrative claim

Driver: `scripts/runtime-harness/checklists/milestone4.php`.

**1. Action registration** — `publishing.generate`,
`publishing.validate_content`, and `publishing.post_process` all
confirmed registered in the real, container-resolved
`ActionRegistryInterface`. PASS.

**2. `GenerateAction` — real `AIManager` orchestration + the ADR-0019
trust boundary:**
- `generate` succeeded end-to-end through the real `AIManager` (real
  validation, caching, rate limiting, retry/failover machinery — only
  the underlying HTTP call is a test double) and created a real,
  database-persisted draft post. PASS.
- Title tags stripped (`<b>Breaking</b> Harness News` → `Breaking
  Harness News`). PASS.
- The AI-generated body's `<script>alert(1)</script>` was stripped by
  `wp_kses_post()` while safe markup (`<p>Real content.</p>`) was
  preserved. PASS.
- The seeded citation's `citationText`
  (`<b>Untrusted</b> Source & Co, 2026.`, deliberately containing
  markup to simulate externally-fetched source text) appeared in the
  persisted post content **only** in its `esc_html()`-escaped form
  (`&lt;b&gt;Untrusted&lt;/b&gt;`); the raw, unescaped markup was
  confirmed absent. This is the concrete, executed proof of ADR-0019
  decision 2 (the citation-reinjection gap the Plan validation pass
  found during design) actually holding at runtime. PASS.
- `_ana_research_session_id` postmeta recorded correctly on the created
  draft. PASS.

**3. `ValidateContentAction` — merges `DefaultEditorialPolicy` +
`ResearchEditorialPolicy`:**
- Passed against a profile whose `min_citation_count`/`min_confidence`
  the seeded research session's one claim/citation/confidence
  satisfies. PASS.
- Failed against a stricter profile (`min_citation_count: 99`) — the
  real `ResearchEditorialPolicy`, resolved from the real container,
  reading the real `_ana_research_session_id` postmeta and calling the
  real `SessionRepositoryInterface::summarize()` against the real,
  persisted `ResearchSession`/`Claim`/`Citation` rows. PASS.

**4. `WorkflowRunContext::priorOutput()` activation** — with no literal
`post_id` in step config, `ValidateContentAction` correctly resolved
`post_id` from a simulated prior `generate` step's output map — the
first real exercise of this previously-unused primitive (the same
"unused extension point, now activated" pattern Milestone 3 established
for `ActionRegistryInterface`). PASS.

**5. `PostProcessAction` — `ana_draft_seo` population:**
- Succeeded and persisted a real row via the real `DraftSeoRepository`
  against real MariaDB. PASS.
- `meta_title` derived and non-empty, `meta_description` free of
  markup, `robots_directives` defaulted to `index,follow`. PASS.

All checks passed on the first run. **No defects were found in this
pass** — Milestone 4 differs from Milestone 2 in this respect (no
concurrency-class bug analogous to D12 surfaced here); the three real
issues this milestone did surface (citation re-escaping, the
second-policy-implementation approach, and the `AIException` →
`WorkflowStepException` retry-classification bridge) were all found and
corrected during the **design/Plan-validation phase**, before any
runtime code existed to test — see ADR-0019's "Alternatives Considered"
section for what each of those would have looked like if shipped
uncorrected.

## Hostinger smoke test — performed, passed

Run by the site owner (this session has no Hostinger credentials) via
`scripts/hostinger/milestone4-smoke-test.php`, a self-contained WP-CLI
`eval-file` script scoped exactly to the owner's requested checklist —
no live AI provider call (an inline fake `ChatProviderInterface`
exercises the full orchestration path instead, since the dev-only
`tests/AI/Fakes/FakeChatProvider` fixture isn't present under this
deployment's `composer install --no-dev`):

```
[PASS] 1: plugin boots and container is available
[PASS] 2: all three actions resolve from the container
[PASS] 3: "publishing.generate" registered
[PASS] 3: "publishing.validate_content" registered
[PASS] 3: "publishing.post_process" registered
[PASS] 4: DraftGeneratedEvent listener invoked
[PASS] 5a: GenerateAction succeeds against real production stack
[PASS] 5b: draft post created
[PASS] 5c: wp_kses_post()/esc_html() trust boundary holds (script stripped, citation escaped)
[PASS] 5d: ValidateContentAction succeeds
[PASS] 5e: PostProcessAction succeeds
[PASS] 6a: ana_draft_seo row persisted against real MySQL
[PASS] 6b: meta_title derived and non-empty
[PASS] 6c: robots_directives default applied

MILESTONE 4 HOSTINGER SMOKE TEST PASSED
```

All test data (draft post, publishing profile, research session/claim/
citation, prompt template version, `ana_draft_seo` row) and the
temporary `ai.defaults.chat` config override were deleted/restored by
the script's own cleanup, confirmed in its output ("Test data cleaned
up: 1 post(s), 1 profile(s), research session ..., prompt template
version ..."). The live site itself was confirmed serving HTTP 200
throughout (`curl -sI https://tfgadgets.com/`), and `wp cli info`
confirmed WP-CLI's PHP binary/version (8.3.30) matched the site's own.

**One real defect found and fixed during this pass:** the smoke test
script fataled on first attempt — `wp eval-file` runs the target file's
content through PHP's `eval()`, which does not accept a leading
`declare(strict_types=1)` as the file's true first statement ("strict_types
declaration must be the very first statement in the script"). This is a
`wp eval-file`-specific incompatibility, not a defect in the actions/
services under test. Fixed by removing `declare(strict_types=1)` from
the smoke test script (every value in it is already explicitly cast, so
no behavior depends on strict typing); re-verified by simulating
`eval-file`'s actual `eval()`-based mechanics locally before asking the
site owner to re-run, then confirmed passing on the real Hostinger
stack. This defect is scoped entirely to the smoke-test script itself —
none of Milestone 4's `src/` files use `wp eval-file` or are affected.

## Remaining known limitations

1. No real AI provider call was exercised anywhere in this milestone's
   verification (by design, per the owner's instruction — a live call
   is optional and cost-gated). The real orchestration layer around
   that call (`AIManager`'s validation/caching/rate-limiting/retry/
   failover/cost-recording/event-dispatch) was fully exercised on both
   the local harness and the live Hostinger stack; only the actual
   HTTP request/response to a real vendor was not.
2. `EditorialPolicyInterface`'s citation-count/confidence checks are
   now implemented (`ResearchEditorialPolicy`) but only for drafts with
   a linked research session — the gap ADR-0018 documented for
   manually-created drafts is unchanged and expected (no research to
   validate against).
3. `PostProcessAction`'s `canonical_url` is left `null` this milestone
   (no permalink source integrated) — a documented, deliberate scope
   limit (ADR-0019 decision 6), not a defect.
4. Pre-existing PHPCS findings in frozen modules remain untouched, per
   established project policy.

## Process improvement: `verify-runtime.sh full`

Per the owner's suggestion after this milestone's local pass:
`./scripts/verify-runtime.sh full` now runs every milestone checklist
(`milestone2 → milestone3 → milestone4`, via an explicit, ordered
`FULL_SEQUENCE` list in the script) sequentially, **stopping at the
first failure** rather than continuing on to later ones — the existing
no-args mode (run everything, report all failures) and explicit-name
mode are both unchanged. This is the regression-suite entry point for
the growing checklist set; future milestones append their checklist
name to `FULL_SEQUENCE`. Verified by running the new mode end-to-end
against real MariaDB.

## Recommendation

**Milestone 4 is frozen as of this report.** Every checklist item has
passed with real, reproducible, assertion-backed evidence — a local
real-database harness covering all six required areas plus the ADR-0019
trust boundary, and a live Hostinger smoke test on the actual deployed
artifact — including the two design-phase-caught issues (citation
escaping, retry classification) now proven correct at runtime rather
than merely reasoned about, and the one genuine defect this pass found
(the `wp eval-file`/`strict_types` incompatibility), fixed and
re-verified before the Hostinger pass was reattempted. Module 8
Milestone 5 (or the next planned module) may begin; its scope is not
yet defined anywhere in this project's design docs or ADRs and should
be scoped following the same audit-before-code discipline as every
prior milestone, not assumed.

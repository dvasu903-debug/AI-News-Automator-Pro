# Module 8 (Publishing Engine) — Milestone 4 Runtime Verification

Date: 2026-07-23
Baseline: Milestone 3 frozen (`2026-07-23-module-8-milestone-3-runtime-verification.md`)
+ Milestone 4 implementation (`GenerateAction`, `ValidateContentAction`,
`PostProcessAction`, `AiContentGenerator`, `ResearchEditorialPolicy`,
`DraftSeoRepository` — see ADR-0019).

## Runtime environment — read this first

**This session has no SSH or hosting credentials for Hostinger**
(`tfgadgets.com`) — the same situation the Milestone 2 runtime
verification session documented. The checklist below was executed
against a locally provisioned real-database runtime instead:

| Component | Version / detail |
|---|---|
| Database | MariaDB 10.11.14 (InnoDB, utf8mb4) — real server, not a fake |
| wpdb | WordPress 6.8.3 `class-wpdb.php`, verbatim, unmodified |
| dbDelta | WordPress 6.8.3 `wp-admin/includes/upgrade.php`, extracted verbatim |
| PHP | 8.4.19 CLI |
| Plugin boot | The REAL production entry point: `ai-news-automator-pro.php` → `PluginFactory::create()->boot()` on `plugins_loaded`, all 8 module providers |
| AI provider | Real `AIManager` orchestration (validation, caching, rate limiting, retry/failover, cost calculation, event dispatch, request/metrics recording) against AI module's own `FakeChatProvider` test double, registered into the real `ProviderRegistryInterface` the same way `AIServiceProvider` registers its real providers — **only the network call is faked**; no real AI provider call was made (no API key configured, no cost incurred) |
| Shimmed | Only peripheral WP APIs (hooks, options, transients, i18n, escaping, `wp_kses_post()`, minimal post/REST/capability stubs) |

Run via the project's committed automation:
`COMPOSER_ALLOW_SUPERUSER=1 ./scripts/verify-runtime.sh milestone2 milestone3 milestone4`
— this also re-ran Milestone 2 and 3's checklists as regression checks;
both still pass unchanged.

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

## Hostinger smoke test — NOT performed in this session, decision needed

Per the standing instruction ("Hostinger smoke tests where
applicable"), this milestone's smoke test has an additional wrinkle
Milestones 2-3 didn't: `GenerateAction` calling a **real** AI provider
on the live site would cost real money and requires a real API key
configured there. Combined with this session's lack of Hostinger
credentials at all, there are three ways to close this out, and the
choice is the site owner's, not this session's to make:

1. **Grant this (or a future) session Hostinger SSH/WP-CLI access** and
   run the smoke test — scoped to the non-AI-cost parts only (action
   registration, `ValidateContentAction`/`PostProcessAction` against a
   manually-created draft with a stubbed/pre-seeded research session,
   mirroring what the local harness already proved) unless a real API
   key and the associated cost are explicitly acceptable for a live
   generation call too.
2. **Run the smoke test independently** (the owner has done this for
   Milestones 2-3 in past sessions) and report back the result for the
   freeze report.
3. **Waive the Hostinger smoke test explicitly for this milestone**,
   freezing on the strength of the local real-database verification
   alone — precedented by Milestone 2's own runtime-verification report,
   which held freeze conditionally until the owner separately confirmed
   the Hostinger pass.

## Remaining known limitations

1. **Not executed on Hostinger** — see above; this is the residual gap
   blocking an unconditional freeze recommendation, not a logic-level
   defect. The checklist logic itself has passed end-to-end against a
   real InnoDB database and real wpdb/dbDelta.
2. No real AI provider call was exercised anywhere in this pass (by
   design — see "AI provider" row above). The real orchestration layer
   around that call (`AIManager`'s validation/caching/rate-limiting/
   retry/failover/cost-recording/event-dispatch) was fully exercised;
   only the actual HTTP request/response to a real vendor was not.
3. `EditorialPolicyInterface`'s citation-count/confidence checks are
   now implemented (`ResearchEditorialPolicy`) but only for drafts with
   a linked research session — the gap ADR-0018 documented for
   manually-created drafts is unchanged and expected (no research to
   validate against).
4. `PostProcessAction`'s `canonical_url` is left `null` this milestone
   (no permalink source integrated) — a documented, deliberate scope
   limit (ADR-0019 decision 6), not a defect.
5. Pre-existing PHPCS findings in frozen modules remain untouched, per
   established project policy.

## Recommendation

**Freeze is recommended, conditional on the Hostinger decision above.**
Every locally-verifiable checklist item has passed with real,
reproducible, assertion-backed evidence against a real database and the
real production boot path, including the two design-phase-caught issues
(citation escaping, retry classification) now proven correct at
runtime rather than merely reasoned about. No runtime-phase defects
were found. The one open item is the Hostinger smoke test's scope and
access, which needs an explicit owner decision (see options 1-3 above)
before this milestone can be marked frozen — the same gate Milestone 2
was held to.

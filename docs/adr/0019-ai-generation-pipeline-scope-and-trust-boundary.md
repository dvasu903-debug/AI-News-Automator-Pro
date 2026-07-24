# ADR-0019: AI-Generation Pipeline Scope and Trust Boundary

**Status:** Accepted · **Module:** 8

## Context

ADR-0018 explicitly deferred three pieces of the Module 8 design to a
future milestone: `GenerateAction` (consuming `AIManager` +
`Research\DTO\ResearchSummary`), AI-backed content validation, and
`PostProcessAction` — a materially larger design surface (prompt
design, LLM-output trust boundary, cost/quality tradeoffs) than
Milestone 3's publish/schedule/validate state transitions. Milestone 4
builds that deferred surface on top of Milestones 1-3's now-frozen
architecture.

This is the first place in the codebase where AI-provider output is
turned into persisted post content rather than consumed as short,
structurally-validated data (e.g. Research's claim/entity extraction,
which never writes to `wp_posts`). `Storage\Repositories\ArticleRepository::createDraft()`
has zero content sanitization (`wp_kses` does not appear anywhere in
`src/` before this milestone) — that was never a gap before, because
nothing untrusted ever reached it. It is one now.

## Decision

**1. `GenerateAction` creates the draft itself — no separate
`CreateDraftAction`.** Step config carries `research_session_id` (the
one identifier needed; `profile_id` is read directly by the downstream
`ValidateContentAction`/publish actions, matching every existing
action's own-step-config convention). Returns
`ActionResult::success(['post_id' => $newId])`. Does **not** implement
`RollbackableActionInterface` — matches every Milestone 3 action's
precedent (no rollback of a create); a later-step failure leaves an
orphaned draft, the same documented characteristic every other
create-shaped action already has.

**2. Citations are deterministic, never AI-generated, and escaped at
splice time.** The AI (`AiContentGenerator`) only ever sees claim
statements (`Research\Entities\Claim::statement`) — never
`Citation::citationText`, never a URL. After the generated body is
sanitized (Decision 3), citations are appended via plain PHP string
building with `esc_html()` around each `citationText`. `citationText`
is deterministic, non-AI-generated data, but it does originate from
externally-fetched source text — splicing it in unescaped would reopen
an injection point immediately after closing the AI one.

**3. `wp_kses_post()` sanitizes AI-generated content inside
`AiContentGenerator`**, immediately after the provider response is
decoded — before the string is ever returned to a caller. Not pushed
down into frozen `DraftRepository`/`ArticleRepository` (generic,
shared by non-AI callers that have never needed this) and not left to
`GenerateAction` alone (the sanitization boundary belongs at the point
untrusted output first enters the system, not one hop downstream).
Documented residual risk: `wp_kses_post()` stops markup/XSS, not
content-level prompt injection (e.g. persuasive but misleading
generated text with no markup). The existing human `approval_gate`
before publish is the mitigation for that — a reviewer sees a rendered
preview, not raw markup, before anything goes live.

**4. No default-seeded prompt template.** `AiContentGenerator` fails
clean (`ContentGenerationException`, mapped by `GenerateAction` to
`WorkflowStepErrorType::Validation` — non-retryable) if
`PromptTemplateRepositoryInterface::getLatest('publishing.article_generation')`
returns null. An unreviewed, hardcoded generation prompt as a silent
fallback is a real content-quality/liability risk for "professional,
trustworthy publishing" — an administrator must deliberately save a
reviewed template version first.

**5. AI-backed content validation reuses the frozen
`EditorialPolicyInterface` itself — a second implementation, not a new
interface.** This directly follows what ADR-0018's own Consequences
section already anticipated: "a future milestone introducing
`ResearchSummary`-based validation extends `DefaultEditorialPolicy` (or
adds a second policy implementation)... since `evaluate()` already
returns a violations list open to more checks." Zero diff to any
frozen Milestone 3 file (`EditorialPolicyInterface`,
`DefaultEditorialPolicy`, `EditorialPolicyResult` are all untouched).
`Publishing\Services\ResearchEditorialPolicy implements EditorialPolicyInterface`
(identical `evaluate(int $postId, PublishingProfile $profile): EditorialPolicyResult`
signature) reads the `_ana_research_session_id` postmeta `GenerateAction`
writes, calls `SessionRepositoryInterface::summarize()`, and checks
`citationCount()` / `overallConfidence` / `hasBlockingContradictions()`
against profile config keys `min_citation_count` / `min_confidence` —
if the postmeta is absent (a manually-created draft with no linked
research session), it passes trivially rather than penalizing
non-AI-assisted drafts. Bound as its own concrete-class container
entry, **not** swapping the existing `EditorialPolicyInterface::class`
binding (which stays `DefaultEditorialPolicy`, so
`PublishDraftAction`/`ScheduleDraftAction`'s Milestone 3 behavior is
unchanged). The new `ValidateContentAction` injects both
`EditorialPolicyInterface $contentPolicy` (existing binding) and
`ResearchEditorialPolicy $researchPolicy` (concrete) and merges both
results' violations, reusing the existing `PublishingRejectedEvent` —
no new rejection event needed.

**6. `PostProcessAction` scoped to SEO metadata population only** —
the one concretely schema-backed candidate (`ana_draft_seo`, built in
Milestone 1, unused until now). Every derivation is strictly
deterministic string manipulation on the post's own title/content
(truncation for meta title/description, longest-word heuristic for a
focus keyword) — never a second `AIManager::chat()` call, which would
reopen the same untrusted-output trust boundary Decision 3 already
closes, with no sanitization plan of its own. `canonical_url` is left
null this milestone (no permalink source integrated). Social
sharing/analytics remain explicitly deferred further, mirroring
ADR-0018's own deferral precedent.

**Retry classification bridge.** `WorkflowStepRetryExecutor::execute()`
catches *any* `\Throwable` and reclassifies it via
`WorkflowStepException::fromThrowable()`, which defaults to
`WorkflowStepErrorType::Unknown` (non-retryable) for anything that
isn't already a `WorkflowStepException` — an uncaught `AIException`
would silently lose its own retryability classification
(`RateLimited`/`ProviderOutage` are legitimately retryable). `GenerateAction`
therefore explicitly catches `AIException` and rethrows
`new WorkflowStepException($e->getMessage(), $e->isRetryable() ? WorkflowStepErrorType::Transient : WorkflowStepErrorType::Validation, $e)`,
delegating to `AIException`'s own classification rather than
duplicating its match logic. `ContentGenerationException` (missing
template) is always rethrown as `WorkflowStepErrorType::Validation` —
retrying a missing-configuration error cannot fix it.

## Consequences

- Milestone 4's surface is: `ContentGeneratorInterface` +
  `AiContentGenerator`, `DraftSeoRepositoryInterface` +
  `DraftSeoRepository`, `ResearchEditorialPolicy` (a second
  `EditorialPolicyInterface` implementation), three new
  `ActionInterface` implementations (`GenerateAction`,
  `ValidateContentAction`, `PostProcessAction`) registered into the
  same `ActionRegistryInterface` Milestone 3 activated, and two new
  events (`DraftGeneratedEvent`, `PublishingCompletedEvent`). No
  changes to any frozen Milestone 1-3 file, `EditorialPolicyInterface`,
  `DraftRepositoryInterface`, or `ArticleRepositoryInterface`.
- `ValidateContentAction`/`PostProcessAction` are the first real
  consumers of `WorkflowRunContext::priorOutput()` — a documented but
  previously-unused primitive (the same shape as Milestone 3 activating
  `ActionRegistryInterface`). They read `post_id` from step config if
  present (for a standalone/manually-triggered run), otherwise from the
  prior `generate` step's output.
- The citation-count/confidence-score gap ADR-0018 documented in
  `EditorialPolicyInterface`'s scope is now closed, but only for drafts
  with a linked research session — `DefaultEditorialPolicy`'s own scope
  (disclosure, word count) is unchanged and still applies to every
  draft regardless of provenance.
- `wp_kses_post()`/`esc_html()` are now load-bearing security controls
  in this codebase's dependency graph for the first time; any future
  change to `AiContentGenerator` that removes or reorders those calls
  reopens the trust boundary this ADR establishes.

## Alternatives Considered

- **Splice raw `Citation::citationText` after sanitizing the AI body.**
  Rejected: `citationText` originates from externally-fetched source
  text, not from this project's own trusted code — unescaped, it
  reopens an injection point immediately after closing the AI one.
- **A new `ResearchEditorialPolicyInterface` separate from
  `EditorialPolicyInterface`.** Rejected: ADR-0018 already anticipated
  extending/adding-a-second-implementation-of the *existing* interface;
  a new interface would diverge from that stated precedent for no
  benefit, since `evaluate()`'s existing signature and
  `EditorialPolicyResult` shape already accommodate the new checks.
  Swapping the `EditorialPolicyInterface::class` binding itself (rather
  than adding a second concrete-class entry) was also rejected — it
  would silently change `PublishDraftAction`/`ScheduleDraftAction`'s
  Milestone 3 behavior for every draft, not just research-linked ones.
- **Let `GenerateAction` catch `AIException` and return
  `ActionResult::failure()`.** Rejected: `ActionResult::failure()` is
  always terminal in `WorkflowRunner` — it never reaches
  `WorkflowStepRetryExecutor`. A legitimately transient failure (rate
  limit, provider outage) would never retry, silently defeating the
  retry mechanism for exactly the case it exists for.
- **Seed a default hardcoded generation prompt so the pipeline always
  has something to run.** Rejected: an unreviewed prompt driving
  published content is a real quality/liability risk this project's
  trust-first principle rules out; failing clean with a clear
  configuration error is the correct behavior until an administrator
  reviews and saves one.
- **A second `AIManager::chat()` call inside `PostProcessAction` for
  higher-quality SEO copy.** Rejected: reopens the exact untrusted-
  output trust boundary Decision 3 closes, for a metadata field with no
  sanitization plan of its own; deterministic derivation from
  already-sanitized content is sufficient for this milestone's scope.

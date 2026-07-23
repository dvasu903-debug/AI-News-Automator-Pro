# Module 6: Research Engine

Consumes normalized data (from Sources' `ItemDiscoveredEvent`, or manual input) and produces structured, evidence-backed research: claims, entities, citations, contradictions, and a confidence-scored summary. **This module never generates publishable content** — that boundary is enforced structurally, not just documented (see §Boundary Enforcement).

Full design rationale in `../../planning/MODULES_6_7_8_DESIGN.md`. This README documents what was built.

## Architecture

```
ResearchSessionManager    → orchestration (mirrors AIManager's role exactly)
SessionRepository          → session CRUD + delegates summarize() to...
ResearchSummaryBuilder     → assembles the authoritative ResearchSummary DTO
AiClaimExtractor /          → AI-backed (via AIManager, structured output)
AiEntityExtractor /
AiContradictionDetector
CompositeConfidenceScorer  → deterministic, evidence-count-based (not an AI call per claim)
HeuristicTopicClusterer    → deterministic, entity-based (not an AI call)
SourceDiversityAnalyzer    → deterministic, computed view
TimelineBuilder            → deterministic, computed view (never persisted)
```

## Schema — 7 tables, Research-owned (ADR-0006: reused Storage migration classes, zero Storage files touched)

| Table | Purpose | Immutability |
|---|---|---|
| `ana_research_sessions` | One investigation per row | Mutable (status/confidence/cluster evolve) |
| `ana_research_evidence` | Source material considered | **Immutable** — no update path exposed |
| `ana_research_claims` | Extracted factual assertions | Statement immutable; status/confidence mutable |
| `ana_research_claim_evidence` | Claim↔Evidence junction (supports/contradicts) | Append-only |
| `ana_research_entities` | Named entities, with mention counts | mention_count increments; nothing else changes |
| `ana_research_citations` | Claim↔Evidence formatted references | **Immutable — write-once**, same discipline as AI's `PromptTemplate` |
| `ana_research_contradictions` | Flagged claim conflicts | `resolved` flips via explicit action only, never automatically |

## Immutable provenance

Two repositories expose **no update method at all**: `EvidenceRepositoryInterface` (`record()`, `find()`, `forSession()` — nothing else) and `CitationRepositoryInterface` (`record()`, `forClaim()`, `forSession()` — nothing else). If a source's content changes, a new Evidence record is created; the old one is never edited. This is enforced at the interface level, not by convention.

## The output contract: `ResearchSummary`

`Research\DTO\ResearchSummary` is **the authoritative contract for all future Publishing work** (Module 8, per the approved design). It deliberately does **not** include a "ready to publish" boolean — that's an editorial *policy* decision belonging to Publishing's own future `EditorialPolicyInterface`, not to Research. Research reports what it found (`hasBlockingContradictions()`, `overallConfidence`, `citationCount()`); it does not decide what's publishable. Assembled exclusively through `SessionRepositoryInterface::summarize()`, which throws `SessionStateException` unless the session is `Completed` — never returns partial data dressed up as final.

## Boundary enforcement (structural, not conventional)

Per the approved requirement, verified in the Architecture Verification Report by direct grep sweep, not just asserted:

- **Zero** import of `Storage\Contracts\ArticleRepositoryInterface` anywhere in `src/Research/`.
- **Zero** import of any `Sources\*` class anywhere in `src/Research/`.
- **Zero** call to `wp_insert_post`/`wp_update_post` anywhere in `src/Research/`.

Research consumes Sources' `ItemDiscoveredEvent` only as an *external trigger it could listen to in a future integration pass* — this module ships the session/evidence/analysis machinery standalone; wiring an actual `ItemDiscoveredEvent` listener is left to whichever future module owns that orchestration decision (see Workflow, Module 7), so Research itself never directly depends on Sources' types.

## Extraction — separated "figure out what's true" from "write it down"

`AiClaimExtractor`/`AiEntityExtractor`/`AiContradictionDetector` only *detect* — none of them touch a repository. `ResearchSessionManager` is the only class that persists what they find. This mirrors the same separation AI's `ChatProviderInterface` (translate) vs. `AIManager` (orchestrate/persist) drew.

**Graceful degradation**: every AI-backed service catches `AIException`, logs, and returns an empty result rather than throwing — one failed extraction on one piece of evidence never aborts an entire session's analysis pass.

**Contradiction detection is O(n) per new claim, not O(n²) total.** `AiContradictionDetector::detectFor()` compares one new claim against a *single batched call* containing all existing claims (bounded to the most recent 30), not N individual pairwise AI calls — bounded cost regardless of session size.

## Confidence scoring — deliberately not an AI call per claim

`CompositeConfidenceScorer` is pure arithmetic: diminishing-returns on supporting-evidence count, a penalty for contradicting evidence, zero if only contradicted. No AI call, no latency, no cost, for the dominant signal. Documented as a deliberate choice, not a missing feature — scoring every claim via `AIManager` would add real cost and latency for a signal a formula already captures well.

## Topic clustering — deliberately not an AI call

`HeuristicTopicClusterer` derives a cluster label from the session's most-mentioned extracted entity (slugified), not another AI call. A dedicated AI-assisted clustering pass is a reasonable future enhancement but isn't the default cost for every session given a cheap, decent heuristic already exists.

## Authorization — new abilities without touching Security

`ResearchAbilityPolicy` (in `Research\Authorization\`, tagged `security.policies`) adds `research.manage` and `research.view` as real, checkable abilities through Security's `PolicyInterface` extension point (Module 2) — **zero modification to Security's frozen `Capabilities` class**. Maps onto `Capabilities::RUN_PIPELINE` and `Capabilities::VIEW_ANALYTICS` respectively, both of which already existed. Verified the tag-resolution timing is correct: `PolicyEngine`'s container binding reads `$container->tagged('security.policies')` lazily (on first resolution, which happens after every module's `register()` phase completes), so Research's tag registration — which happens during its own `register()` phase, after Security's — is picked up correctly regardless of module order.

## REST API

`ResearchSessionController`: `GET/POST /research/sessions`, `GET /research/sessions/{id}`, `GET /research/sessions/{id}/summary`, `POST /research/sessions/{id}/evidence`, `POST /research/sessions/{id}/analyze` (rate-limited — Security's `RestSecurityMiddleware::requireAbilityWithRateLimit()`, since this is the AI-cost-incurring endpoint), `POST /research/sessions/{id}/abandon`. Uses Security's ability-based, audited permission checks (`RestSecurityMiddleware`) rather than `AbstractRestController`'s more basic capability helper — the richer, audited check is the right default for a provenance-focused module.

## Admin UI

`ResearchSettingsPage` — extraction model config, a recent-completed-sessions table, and a health panel. 6th module to extend `AbstractSettingsPage`.

## Testing

78 Research-specific test methods across 10 files (68 test files / 313 methods plugin-wide) — see `docs/verification/2026-07-15-module-6-rc-audit.md` for the release-candidate audit that drove the expansion from the original 40/6. Covers: pure-logic tests for `CompositeConfidenceScorer`, `SourceDiversityAnalyzer`, `HeuristicTopicClusterer`, `TimelineBuilder`; entity/enum tests including `ContradictionSeverity::blocksPublishing()`'s threshold; full `ResearchSessionManager` orchestration tests (session lifecycle, state-transition guards for every terminal-state combination, claim/entity/citation persistence wiring, contradiction severity blocking, event dispatch *ordering*, cross-evidence claim accumulation, topic-cluster persistence); `ResearchAbilityPolicy`'s authorization decision logic (all three `PolicyOutcome`s); and the three AI-backed services' real response-parsing logic (`AiClaimExtractorTest`, `AiEntityExtractorTest`, `AiContradictionDetectorTest` — wired against a real `AIManager` + AI module's own `FakeChatProvider`, so the JSON-decoding/validation code under test is genuinely the production code, not a substitute).

**Same honest limitation as every module**: no PHP/network runtime in this sandbox. Validated structurally (391 total plugin source files, zero brace/namespace/import failures) plus careful manual review of every orchestration file.

## Requirement-by-requirement coverage

Research sessions ✓ · Evidence aggregation ✓ · Claim extraction ✓ (AI-backed) · Entity extraction ✓ (AI-backed) · Topic clustering ✓ (deterministic) · Timeline generation ✓ (computed view) · Citation management ✓ (write-once) · Confidence scoring ✓ (deterministic) · Contradiction detection ✓ (AI-backed, batched) · Source diversity analysis ✓ (deterministic) · Immutable provenance ✓ (structural — no update methods exist) · REST APIs ✓ · Admin UI ✓ · Database schema ✓ (7 tables) · Tests ✓ · Documentation ✓ (this file + design doc).

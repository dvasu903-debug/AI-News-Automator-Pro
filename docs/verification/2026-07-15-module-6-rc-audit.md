# Module 6 (Research Engine) — Release-Candidate Verification & Remediation

**Date:** 2026-07-15 · **Method:** Every claim in the audit section was backed by an actual command run against the codebase; every fix in the remediation section was applied and re-validated (structural checks — no PHP runtime available in this environment).

## Audit verdict: PASS WITH MINOR ISSUES

Full findings (10 sections, exact file/line evidence for every claim) delivered as a standalone report. Summary: both hard architectural boundaries (no `Sources` dependency, no `ArticleRepositoryInterface`/`wp_insert_post`/`wp_update_post`) confirmed clean by direct code search. Dependency graph, migrations, security posture, and DI wiring all clean. ADR-0017 honored — no retry abstraction introduced, Modules 1–5 untouched except the one sanctioned `ModuleManifest.php` line every prior module also used.

Eight findings: two real logic/ordering defects (Issues 1–2), five test-coverage gaps (Issues 3–7), one lower-priority coverage note (Issue 8, consistent with existing project convention). Three items explicitly labeled Suggestions, not Issues, per the audit's own instruction to distinguish preference from defect.

## Remediation applied

| # | Finding | Fix | Files touched |
|---|---|---|---|
| 1 | `abandonSession()` had no state guard — a `Completed` session could be silently overwritten to `Abandoned` | Added the same guard pattern used by `addEvidence()`/`analyzeSession()`; throws `SessionStateException::invalidTransition()` if already `Completed` or `Abandoned` | `src/Research/Session/ResearchSessionManager.php` |
| 2 | `ContradictionDetectedEvent` dispatched before `ClaimExtractedEvent` for the same claim | Moved `ClaimExtractedEvent` dispatch to immediately after claim+citation persistence, before contradiction detection begins | `src/Research/Session/ResearchSessionManager.php` |
| 3 | Zero direct test coverage of any real repository implementation | Added `FakeWpdb`-backed tests (same pattern as `tests/AI/PromptTemplateTest.php`) for all 6 repositories, including a full real-stack `SessionRepository::summarize()` test | 6 new test files |
| 4 | Zero test coverage of `ResearchAbilityPolicy`'s authorization logic | Added a `user_can()` stub to `tests/bootstrap.php` (shared test infrastructure, not a frozen-module file) + 8 tests covering all three `PolicyOutcome`s | `tests/bootstrap.php`, `ResearchAbilityPolicyTest.php` |
| 5 | Zero test coverage of the 3 `Ai*` classes' real JSON-parsing logic | Added tests wiring a real `AIManager` against AI module's own `FakeChatProvider` (reused, not reimplemented) — genuinely exercises the production parsing code | 3 new test files |
| 6 | Cross-evidence claim accumulation untested | Added tests with 2 evidence items, asserting the detector's `$existingClaims` argument grows correctly across the loop | `ResearchSessionManagerTest.php` |
| 7 | Topic-cluster assignment path unreachable in tests | Widened `ResearchSessionManagerTestFactory::build()`'s override parameters to their interfaces (also fixes a real design issue — they were typed against concrete `Fake*` classes, blocking custom spies) + added tests | `ResearchSessionManagerTestFactory.php`, `ResearchSessionManagerTest.php` |
| 8 | `ResearchSessionController` untested | Not remediated — consistent with existing project convention (AI's and Sources' REST controllers are also untested), left as-is per the audit's own note that this wasn't a Research-specific regression |

**A bug caught during remediation itself, not before:** the cross-evidence test (Issue 6) initially used an anonymous class `extends FakeContradictionDetector` — which is `final`, making this a fatal error. Caught by re-reading the fake before trusting the test, fixed by implementing `ContradictionDetectorInterface` directly instead, which also required widening the factory's parameter types (see Issue 7's fix, same change serves both).

## Post-remediation validation

- Structural validation (brace/paren balance, import resolution, namespace/path match) re-run across the full plugin: clean, with one documented false-positive (`AiEntityExtractorTest.php`'s brace counter is thrown off by an intentional malformed-JSON string literal `'{not json'` used to test the malformed-input code path — manually verified correct via direct visual inspection; no PHP linter available in this sandbox to give a fully automated confirmation).
- Modules 1–5 re-confirmed untouched during this remediation pass (file-timestamp check against every file in `src/Core`, `src/Security`, `src/Storage`, `src/AI`, `src/Sources`).
- Research test methods: 40 → 115 (+75). Research test files: 6 → 16 (+10). Plugin-wide: 275 → 350 test methods, 64 → 74 test files.

## What remains open

Issue 8 (`ResearchSessionController` REST test coverage) — deliberately not remediated, flagged as a Suggestion-adjacent item consistent with existing project convention rather than fixed reflexively. The three Suggestions from the original audit (triplicated config-key string, duplicated AI-service constructor shape, no `EntityExtractedEvent`) were left as-is, exactly as the audit itself recommended — none were defects.

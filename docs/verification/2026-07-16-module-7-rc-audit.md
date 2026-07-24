# Module 7 (Workflow Engine) — Release-Candidate Architecture Verification Report

**Date:** 2026-07-16 · **Method:** Every claim below was backed by an actual command run against the codebase in this environment. No PHP runtime is available in this sandbox (network disabled, cannot install `php-cli`), so "Verify" and "Test" are structural: brace/paren/bracket balance, `use`-statement/class-reference resolution against the full project tree, and manual signature cross-checks against every frozen-module contract this module calls — rather than an actual PHPUnit run. This limitation is stated plainly rather than implied away; a real `composer install && vendor/bin/phpunit` pass is still needed before this is genuinely release-ready, and is flagged as the one open item below.

## Audit verdict: PASS

## 1. Frozen-module integrity

`find src/Core src/Security src/Storage src/AI src/Sources src/Research -newer <design-doc-upload-timestamp>` returns exactly one file: **`src/Core/ModuleManifest.php`** — the single sanctioned additive registration entry every prior module also used. Its diff is purely additive: one new array entry (`\AINewsAutomator\Workflow\WorkflowServiceProvider::class`) plus an explanatory comment block, appended after Research's entry. No existing line was altered or removed. Modules 1–6 are otherwise byte-for-byte untouched.

## 2. Structural boundary: `ana_workflows` / `WorkflowRepositoryInterface` unused

Per Part 1's Option A requirement:

- `grep -rn "WorkflowRepositoryInterface"` inside `src/Workflow/` returns **only docblock/comment mentions** explaining why it's *not* used — zero `use` import statements, zero instantiations.
- `grep -rn "Tables::WORKFLOWS"` inside `src/Workflow/` — zero matches.
- Workflow's own repositories (`WorkflowDefinitionRepository`, etc.) each return their own bare logical table name (`workflow_definitions`, `workflow_runs`, `workflow_step_results`, `workflow_approvals`) from `table()`, matching the established per-module convention (`Research\Repositories\SessionRepository::table()` returns `'research_sessions'`, `Sources\Dedup\SourceItemRepository::table()` returns `'source_items'`) rather than Storage's central `Tables` class, which is scoped to Storage's own 11 tables only.

## 3. ADR-0016 / ADR-0017 compliance

- No `WorkflowRetryPolicyInterface` (or any retry interface) exists anywhere in `src/Workflow/` — confirmed by direct `find`. `WorkflowStepRetryExecutor` is a single concrete class, matching `Sources\Retry\SourceRetryExecutor`'s shape exactly (attempt count, base delay, exponential backoff, `LoggerInterface`-only dependency).
- Neither `AI\Manager\RetryExecutor` nor `Sources\Retry\SourceRetryExecutor` was modified (confirmed by the frozen-module integrity check in §1 — neither file's directory appears in the touched-files list).
- **One design-doc self-contradiction was resolved during Build, not silently**: Part 2.1's folder listing included `Contracts/WorkflowRetryPolicyInterface.php`, directly contradicting Part 6's explicit "no `RetryPolicyInterface` abstraction layer." Resolved in favor of Part 6 (the authoritative ADR-0017 compliance section) and the actual `SourceRetryExecutor` precedent. Flagged to the requester before Build began, not discovered after the fact.

## 4. Migration / storage conventions

- 4 new migrations (`20260715400001`–`20260715400004`), no collision with any existing migration version string across Storage (`0000xx`), AI (`1000xx`), Sources (`2000xx`), or Research (`3000xx`) — confirmed by a project-wide duplicate-version-string search.
- Every migration extends `AbstractMigration`, implements `version()`/`description()`/`up()` exactly like the audited `ana_workflows` migration and every other module's migrations, and calls `SchemaBuilder::tableName()`/`SchemaBuilder::charsetCollate()`/`SchemaBuilder::run()` with identical signatures to the confirmed-working examples read during the Audit phase.
- `WorkflowMigrationManifest` mirrors `ResearchMigrationManifest`/`SourcesMigrationManifest`/`AiMigrationManifest` exactly — an explicit, ordered `migrations()` array, applied through the same shared `MigrationRunner` singleton (ADR-0006: Storage reused, not modified).

## 5. Authorization extension point

`WorkflowAbilityPolicy` implements `Security\Contracts\PolicyInterface` and is registered via `$container->tag(WorkflowAbilityPolicy::class, 'security.policies')` — the identical mechanism `ResearchAbilityPolicy` uses. Zero changes to `Security\Authorization\Capabilities` (confirmed by §1 — `src/Security/` does not appear in the touched-files list). Capability mapping matches the approved Decision 4 exactly: `workflow.manage → RUN_PIPELINE`, `workflow.approve → APPROVE_CONTENT`, `workflow.view → VIEW_ANALYTICS`.

## 6. Decision 2 (Scheduler) and Decision 3 (async model) — build notes

- **`WorkflowScheduler` was rebuilt mid-Build.** The first draft used a bare cron tick calling `WorkflowRunner::run()` directly with no queue involvement — simpler, but didn't actually need (or exercise) "the same defensive release-back safety net" the approved Decision 2 explicitly called for. Re-read the confirmation text, recognized the safety net only makes sense if the scheduler genuinely claims from the shared queue, and rebuilt it to enqueue a `workflow.scheduled_run` job and then claim-and-process only that type via `QueueRepositoryInterface::claimNextForWorker()`, releasing any foreign job type back via `release()` — the literal `SourceSyncScheduler` pattern, verified against `SourceSyncScheduler::processQueuedBatch()`'s actual source code during the Audit phase, not assumed from its docblock alone. Covered by `WorkflowSchedulerTest::test_foreign_job_type_is_released_back_not_processed`.
- **`ActionOutcome::AwaitingApproval` was added** — not present in the design doc's `ActionResult` sketch. §2.3 step 7 requires an approval-gate step to halt the run, but the sketched success/failure/deferred shape had no way to express that outcome. Documented inline in `ActionOutcome`'s own docblock rather than added silently.
- Both deviations are additive extensions of the approved design to make explicitly-required behavior mechanically possible, not architectural changes to it.

## 7. Test coverage added

| File | Focus |
|---|---|
| `WorkflowRunnerTest.php` (16 tests) | Full real-stack orchestration: linear success, failure + reverse-order rollback (incl. a non-rollbackable step), deferred + resume, resume idempotency, resume-of-unknown-job safety, approval grant/reject (incl. rollback on reject), condition skip/pass, transient-retry-then-succeed, non-retryable-fails-immediately, missing-action-type failure, version-pinning across a mid-flight new version, event dispatch on completion/failure |
| `WorkflowDefinitionRepositoryTest.php` (8 tests) | Write-once enforcement, no update/save path exists at the method level, `latest()`/`history()`/`allKeys()` |
| `ConditionEvaluatorTest.php` (7 tests) | All operators, fail-closed on malformed/unknown-operator conditions |
| `Retry/WorkflowStepRetryExecutorTest.php` (5 tests) | Retry/exhaustion/non-retryable-type/unclassified-defaults-to-Unknown |
| `Scheduling/WorkflowSchedulerTest.php` (4 tests) | Foreign-job release safety net, scheduled-vs-manual filtering, failure handling |
| `Runner/QueueCompletionListenerTest.php` (3 tests) | `JobCompletedEvent`/`JobFailedEvent` bridging, `willRetry = true` no-op rule |
| `Authorization/WorkflowAbilityPolicyTest.php` (5 tests) | Capability mapping, abstain-on-unhandled-ability |

**12 test files, 47 test methods**, all against real repositories via `FakeWpdb` and a real `WorkflowRunner`/`ConditionEvaluator`/`WorkflowStepRetryExecutor`/`EventDispatcher` — only actions are stubbed (`StubAction`/`StubRollbackableAction`), matching the Module 6 RC audit's lesson (real-repository-stack tests, not in-memory fakes for orchestration) baked in from the start per the approved design's Part 8, rather than remediated after the fact.

`tests/bootstrap.php` was extended additively (a new "Additional stubs for Workflow module unit tests" section: `DAY_IN_SECONDS`, `wp_mail`, `wp_next_scheduled`/`wp_schedule_event`/`wp_unschedule_event`, `get_current_user_id`, `__()`) — the same append-only pattern the existing "Additional stubs for Security module" section established. No existing stub was modified.

## 8. Open item

**No PHP runtime was available in this environment to actually execute the test suite.** Structural verification (brace balance, full-project class-reference resolution, direct signature cross-checks against every frozen contract called) passed cleanly with zero unresolved references across 70 `src/Workflow` files and 12 `tests/Workflow` files, and every method signature used against Core/Security/Storage/AI/Sources/Research was verified by reading the actual source during the Audit phase rather than assumed from memory — but this is not a substitute for `composer install && vendor/bin/phpunit --testsuite=Workflow` in an environment with PHP 8.2+ available. Recommended as the first action before this module is genuinely called complete, ahead of starting Module 8.

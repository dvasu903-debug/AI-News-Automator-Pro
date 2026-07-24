# Module 7: Workflow Engine

Generic step orchestration: define a workflow as an ordered list of typed steps, trigger it (manually, by event, on a schedule, or via REST), and walk it — evaluating per-step conditions, executing actions synchronously or asynchronously, gating on human approval, retrying transient failures, and rolling back best-effort on terminal failure.

Full design rationale in `../../planning/MODULE_7_WORKFLOW_ENGINE_DESIGN.md`. This README documents what was built, including where Build resolved ambiguity the design doc left open.

## Architecture

```
WorkflowRunner              → orchestration (mirrors AIManager / ResearchSessionManager's role exactly)
ActionRegistry               → discovery, mirrors ProviderRegistry / SourceConnectorRegistry exactly
ConditionEvaluator            → structured field/operator/value only — no eval(), no expression language
WorkflowStepRetryExecutor      → ADR-0016/ADR-0017: duplicates SourceRetryExecutor's algorithm, own concrete class
WorkflowScheduler              → independent WP-Cron hook + its own `workflow.scheduled_run` queue job type
QueueCompletionListener        → bridges Storage's JobCompletedEvent/JobFailedEvent to resumeFromQueueJob()
```

## Schema — 4 tables, Workflow-owned (ADR-0006: reused Storage's migration classes, zero Storage files touched)

| Table | Purpose | Immutability |
|---|---|---|
| `ana_workflow_definitions` | One immutable version per row | **Write-once** — same discipline as AI's `PromptTemplate`; no update path exposed |
| `ana_workflow_runs` | One execution instance | Mutable (status/current_step/error evolve); `version` pinned at creation, never changes |
| `ana_workflow_step_results` | One row per step attempt within a run | Mutable while non-terminal; `rollback_status` set once, after the fact |
| `ana_workflow_approvals` | Human approval gates | **Immutable once decided** — `ApprovalRepository::save()` refuses to modify a resolved record |

`Storage\Contracts\WorkflowRepositoryInterface` / `ana_workflows` is **not used anywhere in this module** — confirmed unsuitable during the Audit phase (mutable, no version column, its own docblock says it was built for Module 8), left untouched for that future module. See Part 1 of the design doc.

## Write-once versioning (Part 1, Option A)

`WorkflowDefinitionRepositoryInterface` exposes exactly one write method, `saveNewVersion()` — there is no update/overwrite path at the interface level, not just by convention. A run pins the exact `(workflow_key, version)` it resolved at trigger time and never re-resolves it mid-flight (§2.7, covered by `WorkflowRunnerTest::test_run_pins_the_version_it_started_with_even_if_a_new_version_is_saved_mid_flight`): a definition can gain a new version while a run is still executing, and that run remains fully explainable against the version it actually ran.

## Async steps reuse the existing queue — Decision 3

`ActionInterface::execute()` can return `ActionResult::deferred($queueJobId)`. The Runner marks the step `Deferred` and halts the walk; resumption happens when `QueueCompletionListener` — registered on Core's shared `EventDispatcherInterface`, listening for Storage's existing `JobCompletedEvent`/`JobFailedEvent` — calls `WorkflowRunner::resumeFromQueueJob()`. No second async framework was introduced.

- **Idempotent by construction**: `resumeFromQueueJob()` only acts if `WorkflowStepResultRepositoryInterface::findByQueueJobId()` finds a step still in `Deferred` status; a duplicate completion event for an already-resumed step is a no-op.
- **`willRetry` is respected**: a `JobFailedEvent` with `willRetry = true` means Storage's own queue will retry the job — the listener does *not* resume the step as a failure in that case, or the step's `Deferred` status would desynchronize from the job's actual eventual outcome. Covered by `QueueCompletionListenerTest`.
- **Result payload lookup, not duplication**: neither queue event carries a result/error payload directly (the queue row is already deleted by the time either fires). The listener looks the payload up via the existing `JobHistoryRepositoryInterface::find()` rather than inventing a second channel for it.

## WorkflowScheduler mirrors Sources' claim/release pattern exactly — Decision 2

Approved as its own independent WP-Cron hook, not a migration target for `SourceSyncScheduler`. Verified during the Audit phase that `QueueRepositoryInterface::claimNextForWorker()` cannot filter by job type (Storage is frozen), so `WorkflowScheduler` — like `SourceSyncScheduler` — claims from the *same shared* `ana_queue` table and immediately `release()`s any claimed job whose type isn't its own `workflow.scheduled_run`, rather than mishandling it. Covered by `WorkflowSchedulerTest::test_foreign_job_type_is_released_back_not_processed`.

## Approval gates halt the run, not the process

`ApprovalGateAction` is a near-marker action — it has no side effects of its own; it returns the `AwaitingApproval` outcome, and `WorkflowRunner` (not the action) creates the `Approval` record, dispatches `ApprovalRequestedEvent`, and transitions the run to `AwaitingApproval`. Resolution is an explicit call to `WorkflowRunner::approve()` (REST: `POST /workflow/runs/{id}/approvals/{step_key}`), never automatic.

## Rollback is best-effort, not transactional (§2.5)

On terminal run failure, `WorkflowRunner` walks every `Completed` step in **reverse** order. Three honest outcomes per step: `RolledBack`, `RollbackFailed`, `NotReversible`. An action that doesn't implement `RollbackableActionInterface` at all (e.g. `WaitAction`) is recorded `NotReversible` automatically — the same explicit-not-implicit treatment `NotificationAction` gives itself deliberately, since a sent notification genuinely cannot be undone. One step's `RollbackFailed` never blocks attempting rollback on earlier steps.

## Build-time resolutions (not silently improvised — documented here)

- **`Contracts/WorkflowRetryPolicyInterface.php` was not created.** The design doc's own Part 2.1 folder listing and Part 6 ("no `RetryPolicyInterface` abstraction layer") contradicted each other. Resolved in favor of Part 6 and the actual `SourceRetryExecutor` precedent (no interface at all) — flagged to the requester before Build began.
- **`ActionOutcome::AwaitingApproval` was added.** The design doc's §2.3 step 7 requires an approval gate step to halt the run, but `ActionResult`'s sketched shape (success/failure/deferred) had no way to express that. Added as a fourth outcome during Build, documented inline in `ActionOutcome`'s docblock.
- **`WorkflowScheduler` was rebuilt mid-Build** from an initial simpler design (a bare cron tick calling `WorkflowRunner::run()` directly) to the queue-claim/release version described above, after re-reading Decision 2's explicit requirement for "the same defensive release-back safety net" — which only makes sense if the scheduler actually claims from the shared queue the way `SourceSyncScheduler` does.

## Testing strategy

Mirrors the Module 6 RC audit's lesson: `FakeWpdb`-backed tests for real repositories, not just in-memory fakes for orchestration — baked in from the start rather than deferred. `WorkflowRunnerTest` wires the **real** `WorkflowRunner` against **real** repositories (`FakeWpdb`), a **real** `ConditionEvaluator`, a **real** `WorkflowStepRetryExecutor`, and a **real** `EventDispatcher` — only the actions themselves are test doubles (`StubAction`/`StubRollbackableAction`), since an action is the one genuinely module-external seam per run. Covers: linear success, failure + reverse-order rollback (including a non-rollbackable step), deferred + resume, resume idempotency, resume-of-unknown-job safety, approval grant/reject (including rollback on reject), condition skip/pass, transient-retry-then-succeed, non-retryable-fails-immediately, missing-action-type failure, and version-pinning across a mid-flight new version. Separate focused suites cover `WorkflowDefinitionRepository`'s write-once guarantee, `ConditionEvaluator`'s operators and fail-closed behavior, `WorkflowStepRetryExecutor`'s classification, `WorkflowScheduler`'s claim/release safety net, `QueueCompletionListener`'s `willRetry` handling, and `WorkflowAbilityPolicy`'s capability mapping.

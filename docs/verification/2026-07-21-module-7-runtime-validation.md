# Module 7 (Workflow Engine) — Runtime Validation Report

**Environment:** Hostinger shared hosting, PHP 8.3.30, WP-CLI 2.12.0, real
WordPress + MySQL install (not the PHPUnit fake-DB harness).

**Purpose:** `scripts/validate-module-7.sh` covers automated checks
(PHP syntax, PHPUnit, PHPCS) in sections 0–6, all passing. Sections 7–15
require a real WordPress runtime and are executed and recorded here.

**Status of this document:** in progress — updated after each item as it's
completed, not written retroactively at the end.

---

## Item 7 — Migration execution

**Objective:** confirm all 4 Workflow tables are created correctly on
activation, and that migrations are idempotent (no duplicate application on
deactivate/reactivate).

**Actual:** all 4 tables present (`wp_ana_workflow_definitions`, `_runs`,
`_step_results`, `_approvals`) with schemas matching migration source.
Deactivate/reactivate succeeded with no error. Exactly 4 migration records
(20260715400001–20260715400004), each applied once, batch 5.

**Status: PASS**

---

## Item 8 — Uninstall scope

**Objective:** confirm `WorkflowServiceProvider::uninstall()` removes only
Workflow's own 4 tables.

**Methodology note:** the first attempt used `wp plugin uninstall`, which
triggers the *entire plugin's* uninstall cascade (`Core\Uninstaller`
iterates every `ActivatableInterface` provider) and correctly removed all
25 `wp_ana_*` tables — intended full-uninstall behavior, not a defect, but
not an isolated test of Workflow's method. Re-tested by invoking
`WorkflowServiceProvider::uninstall()` directly via `wp eval` (with
`$plugin->boot()` — an earlier attempt without boot() silently iterated
zero providers, since `Plugin::providers()` is only populated during
boot()).

**Actual (isolated):** before-state had exactly the 4 Workflow tables
(environment mid-recovery from the cascade test); after the isolated call,
0 remained — 4 in, 4 out, correct scoping. Full recovery via real
`wp plugin deactivate`/`activate` restored all 25 tables.

**Environmental finding (documented, pre-existing, all modules):** the
`plugins_loaded`-registered self-healing migration check does not fire
when `boot()` is invoked manually via `wp eval` after WordPress's
`plugins_loaded` has already completed. The real activation path
(`register_activation_hook` → `Activator::activate()`) is reliable and is
the proven recovery mechanism.

**Status: PASS** (cascade and isolated behaviors both correct/intended)

---

## Item 9 — WP-Cron scheduling

**Objective:** confirm `ana_workflow_scheduler_tick` is registered and a
due scheduled workflow is discovered, enqueued, and run to completion.

**Actual:** cron event confirmed scheduled (5-minute recurrence). After
`wp cron event run ana_workflow_scheduler_tick`, a run for the scheduled
test workflow was created and completed
(`id 1, validation-test, completed`).

**Residual gap noted:** the foreign-job-type release-back safety net was
not separately re-verified here (it is unit-tested, and the underlying
claim/release mechanics were independently observed working during Item
14's investigation).

**Status: PASS**

---

## Item 10 — Deferred action resume

**Objective:** a `queue_job` step defers; workflow resumes to completion
once the job completes — using only public services.

### Real defect found and fixed

**Symptom:** every run failed instantly:
`No action registered for type "queue_job" (step "s1")` — with no
step-result row.

**Root cause:** `ActionRegistryInterface` bound via `$container->bind()`
(new instance per resolution) instead of `singleton()` in
`WorkflowServiceProvider::registerRunner()`. `populateActionRegistry()`
filled one instance at boot; `WorkflowRunner` autowired a different,
empty one. Affected every action type. Unit tests construct the runner
directly with a hand-built registry, so only full-container runtime could
expose it. Sibling registries in AI (`ProviderRegistryInterface`) and
Sources (`SourceConnectorRegistryInterface`) confirmed correctly
`singleton()` — an isolated Module 7 deviation.

**Fix:** one-line `bind()` → `singleton()`. (Deployment took several
verification rounds: a stale `/tmp` staging zip and an unbuilt source
extraction each made the fix appear absent on the server; final state was
verified byte-for-byte at source → built zip → deployed directory.)

### Test-design issue found (not a code defect)

Using `job_type=source.fetch` in the test workflow collided with
`SourceSyncScheduler`, which legitimately claims that type from the shared
queue on its own cron tick — jobs vanished before manual claiming was
possible (confirmed: step output `{"skipped":true}` written by Sources'
real handler; queue `Auto_increment` advanced past visible rows).
Re-tested with `job_type=validation.manual_test`, which nothing claims.

### Final evidence

```
run id: 9, status: running
step s1: status=deferred, queue_job_id=7
claimNextForWorker("manual-validation-worker") → claimed job 7
markSuccess(7, {"validated":true})
→ run 9: completed, error NULL; step s1: completed, output {"validated":true}
→ queue row 7 removed (moved to job history)
```

**Status: PASS**

---

## Item 11 — Rollback behavior

**Objective:** failed step fails the run; completed steps roll back in
reverse; non-reversible actions marked, not blocking.

**Evidence:**
```
Run 12: status=failed, current_step_key=notify,
  error=No action registered for type "does_not_exist" (step "fail").
Step notify: status=completed, rollback_status=not_reversible
```
No step-result row for `fail` — confirmed intended by source trace:
`walkFrom()` resolves the action *before* creating any step-result row;
resolution failure calls `failRun()` immediately. `current_step_key`
remaining `notify` is the same mechanism: it is set only after successful
resolution, so it reflects the last step that actually began. Both
documented here for future maintainers.

**Status: PASS**

---

## Item 12 — Approval REST endpoints

**Objective:** exercise the real HTTP REST surface: trigger a run with an
`approval_gate` step, decide via
`POST /workflow/runs/{id}/approvals/{step_key}`, confirm resume, confirm
repeat decision rejected.

**403 investigation — two real findings, no code defect:** initial calls
returned `ana_forbidden` for a genuine administrator. Traced the full
chain (permission_callback → `requireAbilityWithRateLimit()` →
`CapabilityGate` → `PolicyEngine` → `WorkflowAbilityPolicy` →
`user_can()`); the security audit trail (`wp_ana_audit`,
action=authorize) recorded the precise reason:
`Missing required capability "ana_run_pipeline"` — while WP-CLI showed
`user_can(1, "ana_run_pipeline") === true` and core auth
(`/wp/v2/users/me`) returned 200. Root causes:
1. **Stale persistent object cache**: Item 8's full uninstall stripped
   custom capabilities (by design); reactivation re-granted them; the web
   PHP context kept serving the cached pre-grant role definition while
   CLI read fresh. `wp cache flush` resolved it. Operational note for
   this host: flush the object cache after capability-affecting
   lifecycle events.
2. One attempt used a placeholder (invalid) application password,
   correctly producing the anonymous-user deny.
The security layer behaved correctly throughout given what it saw.
Incidental extra validation: ability policy, denial audit trail, and
pair-wise permission_callback invocation (WordPress calls it twice per
request) all observed working.

**Final evidence (real REST, valid credentials, post-flush):**
```
POST .../definitions/approval-test/run
  → {"run_id":31, "status":"awaiting_approval"}
approvals row: run 31, gate, requested_at 19:18:28
POST .../runs/31/approvals/gate (decision=approve)
  → approved, decided_at 19:22:05, decided_by=1
  → run 31 completed; step gate completed
Repeat decision → 409 "No pending approval found for run 31, step gate"
```

**Status: PASS**

---

## Item 13 — Workflow versioning

**Objective:** write-once definitions, in-flight version pinning,
duplicate rejection.

**Evidence:**
```
v1 saved → run 33: pinned version 1, running (deferred)
v2 saved while run 33 in flight
duplicate v2 → rejected: "Cannot overwrite an existing workflow
  definition version."
definitions: exactly versions 1 and 2
run 33: version=1 after v2 existed
```
Incidentally (via Item 14's first pass) run 33's deferred job was
completed and the run finished under version-1 semantics — pinning holds
through resume, not just at start.

**Status: PASS**

---

## Item 14 — Queue recovery after interruption

**Objective (as written in validate-module-7.sh):** a crashed worker's
job is "still claimable (not stuck in processing forever)" — described
there as "Storage's own queue guarantee."

**Finding: that guarantee did not exist.** Source search:
`claimNextForWorker()` claimed only `pending`; `release()` invoked solely
by schedulers declining foreign job types; nothing revisited orphaned
`processing` rows. Empirically proven live: a job claimed by a
never-returning worker was invisible to every subsequent claim
(`claimed: 0` with the queue otherwise empty; the orphan visible in the
table with its stale `locked_at`). The item's premise was an
assumed-but-never-implemented behavior — precisely the class of gap
runtime validation exists to catch. (First empirical attempt was
contaminated by leftover pending state — job IDs in the output exposed
the contamination and it was re-run clean.)

**Decision (explicitly approved — Option 1 of three presented):** narrow
authorized fix in frozen Module 3. `claimNextForWorker()` now begins with
a stale-claim recovery sweep: `processing` jobs whose `locked_at` exceeds
a filterable timeout (`ai_news_automator_queue_stale_lock_timeout`,
default 900s) are re-pended with `attempts + 1` (a crash counts as a
failed attempt) — or, when out of attempts, removed and recorded in job
history as failed with `JobFailedEvent(willRetry: false)`, the same
terminal path `markFailure()` uses. Pending-claim behavior unchanged; a
reclaimed job is immediately claimable in the same call. Registered in
authorized-frozen-changes.txt. Unit coverage:
`tests/Storage/QueueStaleReclaimTest.php` — normal pending claim; stale
reclaim + post-recovery completion; non-stale lock honored; repeated
reclaims exhausting max_attempts into history + terminal event.

Also verified: `release()` works as the manual recovery path when
explicitly invoked.

**Live re-verification after deploying the fix:** the two genuinely
orphaned jobs from the diagnosis phase (id 30 — orphaned by a real
never-completed claim ~19:26; id 33 — lock backdated to simulate elapsed
time) were both automatically reclaimed by a single
`claimNextForWorker("healthy-worker")` call, each showing `attempts=1`
(the crashed execution correctly counted), and both then completed
normally via `markSuccess()`. Queue drained to 0 rows.

**Status: PASS** (defect found → fix approved → implemented → unit-tested
→ deployed → re-verified live on real orphan state)

---

## Item 15 — Event dispatch order

**Objective:** confirm the real, synchronous event sequence matches the
documented state machine:
`WorkflowRunStartedEvent -> StepStartedEvent -> (StepCompletedEvent |
StepFailedEvent | ...) -> ... -> (WorkflowRunCompletedEvent |
WorkflowRunFailedEvent -> RollbackStartedEvent -> RollbackCompletedEvent)`.

**Method:** listeners attached to all 8 Workflow event classes; two real
runs executed through the real container/dispatcher — a clean success
(single `notification` step) and a failure-with-rollback (a completed
step followed by an unresolvable action type, same shape as Item 11).

**Evidence:**
```
SUCCESS PATH: WorkflowRunStartedEvent -> StepStartedEvent ->
  StepCompletedEvent -> WorkflowRunCompletedEvent

FAILURE PATH: WorkflowRunStartedEvent -> StepStartedEvent ->
  StepCompletedEvent -> WorkflowRunFailedEvent -> RollbackStartedEvent ->
  RollbackCompletedEvent
```
Both match the documented order exactly, including
`WorkflowRunFailedEvent` firing *before* the rollback pair. No second
`StepStartedEvent`/`StepFailedEvent` fired for the unresolvable-action
step — consistent with Item 11's finding that action-resolution failures
never reach the step-started stage, so only the run-level failure event
fires for that step; this is the same mechanism observed there, not a
new anomaly, and is now documented at the event-order level too.

**Status: PASS**

---

## Summary

| Item | Area | Result |
|---|---|---|
| 7 | Migration execution | PASS |
| 8 | Uninstall scope | PASS |
| 9 | WP-Cron scheduling | PASS |
| 10 | Deferred action resume | PASS (real defect found & fixed: `ActionRegistry` singleton) |
| 11 | Rollback behavior | PASS |
| 12 | Approval REST endpoints | PASS (two environment findings, no code defect) |
| 13 | Workflow versioning | PASS |
| 14 | Queue recovery after interruption | PASS (real defect found, fixed, and live-reverified: stale-claim reclaim) |
| 15 | Event dispatch order | PASS |

All 9 manual runtime validation items pass with direct evidence, in
addition to the fully automated sections 0–6
(`scripts/validate-module-7.sh`: PHP syntax, PHPUnit, PHPCS).

**Two genuine defects found during this validation, both fixed, tested,
and live-reverified:**
1. `ActionRegistryInterface` bound `bind()` instead of `singleton()` —
   every action type unusable at runtime despite passing all unit tests.
2. `QueueRepository::claimNextForWorker()` had no stale-claim recovery —
   a crashed worker orphaned its job in `processing` forever, contrary
   to the guarantee the validation script itself asserted.

Both were reachable only through real, full-container runtime execution
against a live WordPress + MySQL install — neither was, or could have
been, caught by the PHPUnit suite's fake-DB/manually-wired test harness.
This is the concrete argument for why this runtime validation phase
existed and was worth the time it took.

**One smaller fix (uninstall table-prefix bug) and one process fix
(process substitution → portable temp files) were made earlier in the
Module 7 stabilization phase**, prior to this document's scope; see the
session's PHPCS remediation history and `docs/verification/2026-07-16-module-7-rc-audit.md`.

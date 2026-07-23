#!/usr/bin/env bash
#
# Module 7 (Workflow Engine) — Runtime Validation Script
#
# Run this in a REAL environment with PHP 8.2+, Composer, and (for the
# WP-Cron/queue/scheduler sections) a working WordPress install — none
# of which are available in the sandbox that built this module. This
# script implements every check from the "Before freezing Module 7"
# list that can be automated; a few (WP-Cron execution, queue-recovery-
# after-interruption) need a running WordPress site and are marked
# MANUAL below with exact steps.
#
# Usage: run from the plugin root (same directory as composer.json).
#   chmod +x scripts/validate-module-7.sh
#   ./scripts/validate-module-7.sh 2>&1 | tee module-7-validation-output.log
#
# Exit code is non-zero if any automated check fails.

set -uo pipefail
FAIL=0
section() { echo; echo "=================================================="; echo "  $1"; echo "=================================================="; }

section "0. PHP version check"
php -v || { echo "FAIL: php not found"; exit 1; }
php -r 'if (version_compare(PHP_VERSION, "8.2.0", "<")) { echo "FAIL: PHP 8.2+ required\n"; exit(1); } echo "OK: PHP " . PHP_VERSION . "\n";' || FAIL=1

section "1. Install dependencies"
composer install --no-interaction --prefer-dist
[ $? -eq 0 ] && echo "OK: composer install" || { echo "FAIL: composer install"; FAIL=1; }

section "2. PHP syntax validation (php -l) — every file in src/ and tests/"
SYNTAX_FAIL=0
PHP_FILE_LIST="$(mktemp)"
find src tests -name "*.php" -print0 > "$PHP_FILE_LIST"
while IFS= read -r -d '' f; do
    php -l "$f" > /tmp/lint_out.txt 2>&1
    if [ $? -ne 0 ]; then
        echo "SYNTAX ERROR in $f:"
        cat /tmp/lint_out.txt
        SYNTAX_FAIL=1
    fi
done < "$PHP_FILE_LIST"
rm -f "$PHP_FILE_LIST"
[ $SYNTAX_FAIL -eq 0 ] && echo "OK: php -l clean across all files" || { echo "FAIL: syntax errors found above"; FAIL=1; }

section "3. Workflow test suite only"
vendor/bin/phpunit --testsuite=Workflow --testdox
[ $? -eq 0 ] && echo "OK: Workflow test suite passed" || { echo "FAIL: Workflow test suite"; FAIL=1; }

section "4. Complete plugin test suite (all modules)"
vendor/bin/phpunit --testdox
[ $? -eq 0 ] && echo "OK: full plugin test suite passed" || { echo "FAIL: full plugin test suite — check whether any pre-existing (non-Workflow) test regressed"; FAIL=1; }

section "5. PHPCS (WordPress Coding Standards, via project ruleset — see phpcs.xml.dist)"
vendor/bin/phpcs --standard=phpcs.xml.dist src/Workflow/ --report=summary
PHPCS_EXIT=$?
if [ $PHPCS_EXIT -eq 0 ]; then
    echo "OK: PHPCS clean"
elif [ $PHPCS_EXIT -eq 1 ]; then
    echo "PHPCS found coding-standard warnings/errors (see above) — review before freeze; not necessarily a blocker depending on severity"
else
    echo "FAIL: PHPCS itself errored — check installation"
    FAIL=1
fi

section "6. PHPStan"
if [ -f phpstan.neon ] || [ -f phpstan.neon.dist ] || [ -f phpstan.dist.neon ]; then
    vendor/bin/phpstan analyse src/Workflow --level=5
    [ $? -eq 0 ] && echo "OK: PHPStan clean" || { echo "FAIL: PHPStan found issues"; FAIL=1; }
else
    echo "SKIPPED: no phpstan.neon found and PHPStan is not a composer dev dependency in this project — not configured project-wide, consistent with 'if configured'. Not a Module 7-introduced gap."
fi

section "7. Migrations apply successfully (requires WP_TESTS_DB or a real WordPress+MySQL environment)"
cat <<'EOF'
MANUAL — run inside a WordPress install with this plugin active:
  1. Fresh DB (or a snapshot pre-Module-7): activate the plugin, confirm
     Modules 1-6 tables exist as before.
  2. Trigger Workflow's migration check (plugins_loaded priority 8, or
     call WorkflowServiceProvider::activate() directly via WP-CLI:
       wp eval 'do_action("activate_ai-news-automator-pro/ai-news-automator-pro.php");'
  3. Confirm all 4 new tables exist with correct schema:
       wp db query "SHOW TABLES LIKE '%workflow%'"
       wp db query "DESCRIBE wp_ana_workflow_definitions"
       wp db query "DESCRIBE wp_ana_workflow_runs"
       wp db query "DESCRIBE wp_ana_workflow_step_results"
       wp db query "DESCRIBE wp_ana_workflow_approvals"
  4. Confirm migration is idempotent: deactivate/reactivate, confirm no
     duplicate-table error and MigrationRecorder shows each of the 4
     migration versions recorded exactly once.
EOF

section "8. Uninstall removes only Workflow-owned tables"
cat <<'EOF'
MANUAL:
  1. Note full table list before uninstall: wp db query "SHOW TABLES LIKE 'wp_ana_%'"
  2. Trigger WorkflowServiceProvider::uninstall() (plugin uninstall flow,
     or directly via WP-CLI eval against the container).
  3. Confirm exactly these 4 tables are gone:
       wp_ana_workflow_approvals, wp_ana_workflow_step_results,
       wp_ana_workflow_runs, wp_ana_workflow_definitions
  4. Confirm every other wp_ana_* table (Core/Security/Storage/AI/
     Sources/Research-owned) is still present and untouched.
EOF

section "9. WorkflowScheduler executes correctly with WP-Cron"
cat <<'EOF'
MANUAL:
  1. Activate the plugin; confirm the cron hook is scheduled:
       wp cron event list | grep ana_workflow_scheduler_tick
  2. Save a workflow definition with trigger.type = "scheduled" and a
     short trigger.config.interval_seconds (e.g. 30) via the REST
     endpoint or directly via WorkflowDefinitionRepository.
  3. Force the tick: wp cron event run ana_workflow_scheduler_tick
  4. Confirm a workflow.scheduled_run job was enqueued to wp_ana_queue,
     then claimed and processed (job row moves to wp_ana_jobs / history
     with status=completed), and a new row appears in
     wp_ana_workflow_runs for that workflow_key.
  5. Seed a foreign-type job directly into wp_ana_queue (any job_type
     other than workflow.scheduled_run, e.g. simulate a Sources job)
     and confirm the next tick releases it back to pending rather than
     marking it failed or processing it — this is the release-back
     safety net (§3 of the RC audit) and the one behavior most worth
     confirming for real, since FakeQueueRepository's claimNextForWorker
     is a simplified model of the real one.
EOF

section "10. Deferred actions resume correctly from the queue"
cat <<'EOF'
MANUAL:
  1. Run a workflow whose first step uses the queue_job action type
     against a real, slow-ish job type.
  2. Confirm the run shows status=running and the step shows
     status=deferred with a queue_job_id set, immediately after
     triggering.
  3. Let the real queue worker process the job to completion.
  4. Confirm JobCompletedEvent fires (Storage's real event, not a test
     double) and the workflow run resumes and reaches the next step or
     completes.
  5. Manually re-dispatch a JobCompletedEvent for the same job_id a
     second time (or re-trigger completion) and confirm nothing happens
     the second time — this is the idempotency guarantee
     (QueueCompletionListenerTest covers this in isolation; this step
     confirms it holds against the real Storage event, not just the
     test double).
EOF

section "11. Rollback behavior"
cat <<'EOF'
MANUAL:
  1. Run a workflow with 2+ steps using real rollbackable actions (or
     NotificationAction, which is intentionally NotReversible) followed
     by a step that fails.
  2. Confirm rollback_status is recorded on each completed step in
     reverse order, and that a NotReversible step doesn't block
     rollback of earlier steps.
EOF

section "12. Approval workflow"
cat <<'EOF'
MANUAL:
  1. Run a workflow containing an approval_gate step; confirm the run
     halts at status=awaiting_approval and a pending approval row
     exists.
  2. Call POST /workflow/runs/{id}/approvals/{step_key} with
     decision=approve as a user with the ana_approve_content capability;
     confirm the run resumes and completes.
  3. Repeat with decision=reject; confirm the run fails and rolls back.
  4. Attempt to decide the same approval twice; confirm the second call
     is rejected (immutable-once-decided).
EOF

section "13. Workflow versioning"
cat <<'EOF'
MANUAL:
  1. Save workflow definition version 1, start a run, let it pause on a
     deferred step.
  2. Save version 2 of the same workflow_key with a different step
     structure.
  3. Resume the version-1 run to completion; confirm (via
     GET /workflow/runs/{id}) it executed version 1's steps throughout,
     not version 2's.
  4. Attempt to POST the same version number again; confirm 422/
     ValidationException (write-once).
EOF

section "14. Queue recovery after interruption"
cat <<'EOF'
MANUAL:
  1. Start a run with a deferred step; find its queue_job_id.
  2. Simulate a crash: kill the queue worker process / restart PHP-FPM
     mid-processing.
  3. Confirm the job is still claimable (not stuck in "processing"
     forever) after the worker restarts — this is Storage's own queue
     guarantee, but confirm Workflow's step correctly stays Deferred
     and resumes once the job eventually completes or fails, rather
     than the run being left in an unrecoverable state.
EOF

section "15. Event dispatch order"
cat <<'EOF'
MANUAL / semi-automatable — add a temporary listener on every
Workflow\Events\* class logging (event class, timestamp) to a file
during a full run exercising every path (success, failure+rollback,
deferred+resume, approval+reject), then confirm the observed order
matches: WorkflowRunStartedEvent -> StepStartedEvent -> (StepCompletedEvent
| StepFailedEvent | StepDeferredEvent | ApprovalRequestedEvent) -> ...
-> (WorkflowRunCompletedEvent | WorkflowRunFailedEvent -> RollbackStartedEvent
-> RollbackCompletedEvent). WorkflowRunnerTest already asserts individual
dispatches happen; this step confirms real end-to-end ORDER, which unit
tests with a synchronous EventDispatcher only partially exercise (no
listener can reorder or delay in production the way an async listener
theoretically could).
EOF

section "16. Frozen Modules 1-6 unchanged except authorized changes (see docs/verification/authorized-frozen-changes.txt)"
CHANGED_LIST="$(mktemp)"
find src/Core src/Security src/Storage src/AI src/Sources src/Research -newer planning/MODULE_7_WORKFLOW_ENGINE_DESIGN.md -type f > "$CHANGED_LIST" 2>/dev/null
ALLOWLIST="docs/verification/authorized-frozen-changes.txt"
UNAUTHORIZED=""
while IFS= read -r f; do
    [ -z "$f" ] && continue
    if [ -f "$ALLOWLIST" ] && grep -Fxq "$f" "$ALLOWLIST"; then
        echo "authorized: $f"
    else
        echo "NOT authorized: $f"
        UNAUTHORIZED="yes"
    fi
done < "$CHANGED_LIST"
rm -f "$CHANGED_LIST"
if [ -z "$UNAUTHORIZED" ]; then
    echo "OK: every changed frozen-module file is on the authorized list"
else
    echo "FAIL: unauthorized frozen-module change(s) found above — either revert, or add to $ALLOWLIST with a documented reason"
    FAIL=1
fi

section "SUMMARY"
if [ $FAIL -eq 0 ]; then
    echo "All AUTOMATED checks passed. Complete the MANUAL sections (7-15) against a real WordPress+MySQL+WP-Cron environment before freeze."
else
    echo "One or more AUTOMATED checks FAILED. Do not freeze. Fix Module 7 only, then re-run this script."
fi
exit $FAIL

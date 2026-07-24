# Module 8 (Publishing Engine) — Milestone 2 Runtime Verification & Freeze Report

Date: 2026-07-23
Baseline: `0bfa4d3` (SOURCE-for-build-18 sync) + `33a4c49` (r4 overlay +
wp_json_encode fix) + `76329a2` (D12 race fix, found by this pass).

## Runtime environment — read this first

The Milestone 2 freeze checklist names Hostinger as the runtime target.
**This verification session had no access to the Hostinger server** (no
SSH or hosting credentials are available to it), so the checklist was
executed instead against a locally provisioned real-database runtime:

| Component | Version / detail |
|---|---|
| Database | MariaDB 10.11.14 (InnoDB, utf8mb4) — real server, not a fake |
| wpdb | WordPress 6.8.3 `class-wpdb.php`, verbatim, unmodified |
| dbDelta | WordPress 6.8.3 `wp-admin/includes/upgrade.php`, extracted verbatim |
| PHP | 8.4.19 CLI |
| Plugin boot | The REAL production entry point: `ai-news-automator-pro.php` → `PluginFactory::create()->boot()` on `plugins_loaded`, all 8 module providers |
| Shimmed | Only peripheral WP APIs (hooks, options, i18n, escaping), mirroring `tests/bootstrap.php`'s approach |

Every result below is from actual execution in that environment. What
this does NOT cover: Hostinger's specific PHP build/MySQL version and
hosting-layer behavior. See "Recommendation" for how that residual gap
is handled.

## Checklist results

### C. Container identity probe — PASS
`PublishingProfileRepositoryInterface` resolved twice from the booted
production container: identical `spl_object_id`. Same for
`PublishingProfileService`. The Module 7 `bind()`/`singleton()` defect
class does not recur.

### D6. Migrations via plugins_loaded self-healing — PASS
Fresh database → firing `plugins_loaded` through the real entry point
ran every module's migration manifest: 27 migrations recorded, 28
`wp_ana_*` tables created, including all three Publishing tables.
`wp_ana_publishing_profiles` has exactly the 11 columns of the frozen
Milestone 1 schema + Milestone 2's `is_default` (`tinyint(1) NOT NULL
DEFAULT 0`, confirmed via `SHOW COLUMNS`). All four Publishing
migration versions (`20260722100001`–`20260722100004`) recorded once.
**Idempotency:** a second full boot applied zero new migrations, zero
duplicate versions.

### D7. Slug/name uniqueness — PASS (with one recorded decision)
`SHOW INDEX`: `slug` has a DB-level UNIQUE index (from the frozen
Milestone 1 migration — no schema change needed). `name` has no
DB-level unique index; uniqueness for `name` is service-level only
(P1 via `existsWithName`). Decision recorded: acceptable for Milestone
2 — names are human labels, the race window is administrative UI, and
adding an index would require a new migration; revisit only if a real
collision is observed.

**D7b (closes the local incomplete test):** the `$excludeId` path of
`existsWithSlug()`/`existsWithName()` — the `Filter::notEquals()` SQL
that `FakeWpdb` cannot model — was exercised against real MySQL in all
four directions (own-id excluded → false, other-id excluded → true).
All pass. The coverage gap behind the one incomplete PHPUnit test is
now closed by real-database execution, exactly as the r4 package
prescribed.

### D8. Full CRUD cycle — PASS
create (id assigned), findById, findBySlug, findAll /
findAll(enabledOnly), update, delete — all round-tripped correctly.
Timestamps written UTC (0s drift vs `UTC_TIMESTAMP`-equivalent check)
and hydrated as `DateTimeImmutable` in UTC.

### D9. utf8mb4 / JSON round-trip — PASS
Profile name with emoji (`Intl 🌍 Profile`) and config containing
emoji + ZWJ sequence + flag (`🎉👩‍💻🇩🇪`), CJK (`こんにちは世界`), and RTL
Arabic — byte-perfect round-trip; raw column content decodes as valid
UTF-8 JSON with the 4-byte characters intact.

### D10. markDefault() promote/demote — PASS
First mark → exactly one default; switch → exactly one; six alternating
switches → exactly one.

### D11. Transaction rollback probe — PASS
Forced exception mid-transaction (demote-all + promote inside
`TransactionManager::transactional()`, then throw): everything rolled
back, the previous default remained intact, still exactly one default.

### D12. Concurrent markDefault() — **FAILED, defect fixed, now PASS**
The one real defect this pass found. Two parallel PHP processes, 50
alternating `markDefault()` calls each against different profiles:
**two rows ended with `is_default = 1`** — single-writer invariant
violated. Root cause: the demote step used an unlocked SELECT to find
the current default, then demoted only that row; under InnoDB
REPEATABLE READ two transactions can both snapshot-read the same stale
default, each demote just it, and each promote its own target.

Fix (commit `76329a2`, repository-internal, interface/service/DTO/tests
untouched): demote is now a single blanket exact-match UPDATE
(`SET is_default = 0 WHERE is_default = 1`) inside the same
transaction — it takes row locks on every currently-default row, so
concurrent calls serialize, and UPDATE's current-read semantics
re-evaluate after a blocking transaction commits.

After the fix: three D12 runs (300 interleaved switches total, plus one
more on a fresh database) — always exactly one default, zero worker
errors. PHPUnit still 490 passing / 1 incomplete; PHPCS shows no new
findings on the file.

### D13. requireDefault() failure path — PASS
With zero default rows, `requireDefault()` throws
`PublishingConfigurationException` with the exact configured message —
no fatal, no silent fallback.

### D14. Policies live — PASS
Deleting the default → rejected (`ProfileValidationException`), row
still present. Disabling the default → rejected, still enabled.
Marking a disabled profile default → rejected, default unchanged.
Duplicate slug on create → `DuplicateSlugException`.

## Workflow integration verification

Milestone 2 deliberately has no Workflow-internal dependency (see
`ModuleManifest`'s Publishing entry and the design doc §1). What was
verified at runtime: the full 8-provider boot — Workflow and Publishing
registered and booted together in production order — with no binding
conflicts, both modules' migrations coexisting in one
`ana_schema_migrations` ledger, and the shared `MigrationRunner`
singleton serving both. The `requireDefault()` failure contract that
future workflow-context calls rely on (D13) throws a typed exception
rather than fataling. Deeper integration (Publishing actions inside
workflow runs) is Milestone 3+ scope by design.

## Defects found and resolved (this pass)

1. **D12 concurrency race in `markDefault()`** — fixed as above
   (`76329a2`). The only code change of the runtime pass; two
   harness-side probe errors (a wrong expected-column list and a
   placeholder-less `$wpdb->prepare()` call in the probe itself) were
   probe bugs, fixed in the probe, not plugin defects.

## Remaining known limitations

1. **Not executed on Hostinger itself** — this session has no access to
   that server. The checklist logic has now passed end-to-end against a
   real InnoDB database and real wpdb/dbDelta, so the residual risk is
   environment-specific (Hostinger's PHP/MySQL versions, hosting
   restrictions), not logic-level.
2. The one PHPUnit incomplete test remains incomplete by design
   (FakeWpdb `!=` gap); its underlying behavior is now
   execution-verified against real MySQL (D7b). Log in the Technical
   Debt Register at freeze.
3. `name` uniqueness is service-level only (D7 decision above).
4. No static-analysis tool configured repository-wide (pre-existing).
5. Pre-existing PHPCS findings in frozen modules remain untouched.

## Recommendation for Milestone 2 Freeze

**Freeze is recommended, with one condition.** Every checklist item
(A local pipeline, B contract alignment — proven by execution rather
than diff, C identity probe, D6–D14) has now actually passed, and the
one defect runtime verification exposed (D12) is fixed and re-verified.
The condition: because the checklist names Hostinger as the target and
this pass necessarily ran on an equivalent-but-local real-database
runtime, a short smoke pass on the actual Hostinger install (activate
plugin → confirm `is_default` column exists → one markDefault switch →
one `requireDefault()` call) should either accompany the freeze or be
explicitly waived by the owner. Everything heavier — concurrency,
rollback, utf8mb4, policies — has been genuinely executed and does not
depend on the hosting layer.

E15 (ROADMAP/CHANGELOG/RELEASE_NOTES updates and the freeze marking
itself) is deliberately NOT performed here — it is the freeze act, and
this report's approval is its gate.

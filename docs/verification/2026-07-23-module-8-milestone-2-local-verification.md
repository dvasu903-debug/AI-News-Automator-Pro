# Module 8 (Publishing Engine) — Milestone 2 Local Verification Report

Date: 2026-07-23
Scope: r4 overlay applied to the SOURCE-for-build-18 baseline; full local
verification pipeline executed. Every result below is from an actual
command execution in the local development environment — nothing is
estimated or carried over from the r4 package's unexecuted claims.

## Environment

| Component | Version |
|---|---|
| PHP (CLI) | 8.4.19 (NTS, Debian) |
| Composer | 2.8.12 |
| phpunit/phpunit | 10.5.64 |
| squizlabs/php_codesniffer | 3.x (via composer.json `^3.9`) |
| wp-coding-standards/wpcs | 3.4.0 |
| OS | Linux 6.18.5 |

Dependencies installed with `composer install --no-interaction
--prefer-dist` (clean install; `vendor/bin/{phpunit,phpcs,phpcbf,php-parse}`
present afterward).

## Baseline and overlay

- Baseline: commit `0bfa4d3` — repository synchronized byte-for-byte to
  `SOURCE-for-build-18.zip` (610 files; verified by full `cmp` sweep).
- Overlay: the approved Milestone 2 r4 package (both uploaded copies were
  md5-identical: `1f83bb916a54c201f859b3f5277adb81`). 17 new files, 2
  overwrites (`PublishingServiceProvider.php`,
  `PublishingMigrationManifest.php`), both verified purely additive over
  Milestone 1 before applying. No merge conflicts; every applied file
  byte-identical to the package. Committed as `33a4c49`.

## Commands executed

```bash
composer install --no-interaction --prefer-dist
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l   # per-file loop
vendor/bin/phpunit                                            # full suite
vendor/bin/phpunit --testsuite=Publishing
vendor/bin/phpunit --display-incomplete
vendor/bin/phpcs --standard=phpcs.xml.dist src/Publishing/
vendor/bin/phpcs --standard=phpcs.xml.dist tests/Publishing/
composer lint          # = phpcs --standard=phpcs.xml.dist src/
php <manifest-load probe>   # see "Migration verification" below
```

## 1. PHP syntax validation (`php -l`)

**PASS.** No syntax errors detected in any of the **573** PHP files in
`src/` and `tests/`, including each of the 19 r4 files individually.

## 2. PHPUnit

**PASS.** Full suite (post-fix confirmation run):

```
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.
Runtime: PHP 8.4.19
Tests: 490, Assertions: 826, Incomplete: 1.
0 failures, 0 errors.
```

Publishing suite alone: **65 tests, 120 assertions** (7 Milestone 1
DraftRepository tests + the 58 new Milestone 2 tests: DTO 12,
validator 12, service 17, repository 17), matching the r4 delivery
notes' expected counts exactly.

The single incomplete is the one the r4 package documents and the owner
directed to remain as-is:
`PublishingProfileRepositoryTest::test_exists_with_slug_exclude_id_needs_real_mysql_verification`
— `tests/Storage/FakeWpdb.php::applyWhere()` does not support the `!=`
operator `Filter::notEquals()` generates for the `$excludeId` path.
Deferred to Hostinger runtime validation (freeze checklist item D7) and
to be logged in the Technical Debt Register at freeze.

## 3. PHPCS (project standard, `phpcs.xml.dist` / WPCS 3.4.0)

`src/Publishing/` after the defect fix below: **11 errors, 5 warnings in
5 files**, all belonging to finding classes the frozen baseline already
carries (counts from a same-session run against frozen modules):

| Sniff | Publishing | Frozen baseline (src/, excl. Publishing) |
|---|---|---|
| `WordPress.Security.EscapeOutput.ExceptionNotEscaped` (exception messages, not output) | 11 | 121 |
| `Universal.NamingConventions.NoReservedKeywordParameterNames` (`$default`) | 2 | 20 |
| `WordPress.DB.DirectDatabaseQuery.*` (uninstall() DDL, identical pattern in every module) | 3 | ~40 |

`tests/Publishing/`: 5 errors, 2 warnings — same classes present in the
baseline test suite (`$GLOBALS['wpdb']` assignment: 7 occurrences in
frozen tests; multiple-classes-per-file and anon-class style likewise
pre-existing). Note `phpcs.xml.dist` and `composer lint` only target
`src/`; the tests run was extra, per the r4 runbook's suggestion.

`composer lint` (full `src/`) exits 1 with 168 errors / 108 warnings —
2 fewer errors and 2 fewer warnings than the pre-overlay-fix state, all
remaining findings pre-existing baseline classes. The project has never
been PHPCS-clean; per `scripts/validate-module-7.sh`'s established
policy, exit 1 is "review before freeze", not an automatic blocker, and
the ruleset's suppressed-formatting policy is documented in
`phpcs.xml.dist` itself.

## 4. Static analysis

**Not configured — not run.** Verified explicitly rather than skipped
silently: no `phpstan.neon`/`phpstan.neon.dist`/`phpstan.dist.neon`, no
`psalm.xml`(`.dist`), and no static-analysis package in `composer.json`
require-dev. Consistent with `validate-module-7.sh` section 6, which
records the same state. Adding PHPStan would be a new tooling decision
outside Milestone 2 scope.

## 5. Migration verification

**PASS.** Probe script loaded via the real Composer autoloader +
`tests/bootstrap.php` shims:

- `PublishingMigrationManifest::migrations()` returns **4** entries in
  order: `20260722100001` (CreatePublishingProfilesTable),
  `20260722100002` (CreatePublishingRunsTable), `20260722100003`
  (CreateDraftSeoTable), `20260722100004`
  (AddIsDefaultToPublishingProfilesTable).
- All four instantiate without error and implement
  `Storage\Contracts\MigrationInterface`.
- Versions are unique and strictly ordered; the three Milestone 1
  entries are unmodified; the new migration is additive-only.
- `dbDelta`-level column/index verification requires a real
  WordPress+MySQL install and is deliberately deferred to Hostinger
  runtime validation (runbook step 5 / checklist D-items), as is the
  container identity probe (runbook step 6).

## 6. Defects found and resolved

**One execution-verified defect, fixed (2 lines).** PHPCS flagged raw
`json_encode()` in `PublishingProfile::configJson()` and
`PublishingProfileValidator::validateConfigEncodable()` — the only two
raw `json_encode()` calls anywhere in `src/` (grep-verified); every
frozen module uses `wp_json_encode()`. Both switched to
`wp_json_encode()` (flags preserved — WordPress's signature accepts
them; the test bootstrap already shims the function; the only test
touching `configJson()` asserts on a decode round-trip, so behavior is
unchanged). Full suite re-run after the fix: identical green result.

No other findings qualified as defects: PHPUnit had zero failures, and
every remaining PHPCS finding class is baseline-established style the
approved architecture deliberately carries (fixing them only in
Publishing would diverge from frozen-module convention; fixing them
everywhere would be unauthorized frozen-module refactoring).

## 7. Remaining known limitations

1. `existsWithSlug()`/`existsWithName()` `$excludeId` path unverified
   locally (FakeWpdb `!=` gap) — one incomplete test, deferred to
   Hostinger D7; log in Technical Debt Register at freeze.
2. Real-database migration behavior (dbDelta diff, `is_default` column
   + index creation, slug UNIQUE index untouched, no data loss) not
   exercisable locally — Hostinger runtime validation.
3. Container identity probe (`spl_object_id` twice-resolve) requires a
   running WordPress container — Hostinger runtime validation.
4. No static-analysis tool configured repository-wide (pre-existing).
5. Pre-existing PHPCS findings in frozen modules (168 errors/108
   warnings repo-wide) remain untouched by design.

## Commits

- `0bfa4d3` — baseline synchronization to SOURCE-for-build-18.
- `33a4c49` — r4 overlay + the 2-line `wp_json_encode()` fix.

Hostinger validation and Milestone 2 Freeze have **not** been started,
pending review of this report.

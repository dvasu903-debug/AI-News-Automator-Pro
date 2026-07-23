# Runtime Verification Harness

Reusable infrastructure for the real-database runtime verification pass
this project runs before freezing a milestone (established for Module 7,
refined through Module 8 Milestones 2-3 — see
`docs/verification/*-runtime-verification.md`). Invoked via
`scripts/verify-runtime.sh`; you should not normally need to touch these
files directly.

## What's here

- `harness-bootstrap.php` — shims the peripheral WordPress API surface
  (hooks, options, transients, i18n, escaping, minimal post/REST/
  capability stubs), then loads the *real* WordPress core `wpdb` class
  and `dbDelta()` (fetched fresh by `verify-runtime.sh`, see below) and
  boots the plugin through its actual production entry point
  (`PluginFactory::create()->boot()` on `plugins_loaded`).
- `extract-dbdelta.php` — pulls `dbDelta()` and
  `wp_should_upgrade_global_tables()` out of a downloaded
  `wp-admin/includes/upgrade.php` using PHP's tokenizer to find function
  boundaries (robust across WordPress versions — not a fixed line-range
  slice, which would silently break the moment `WP_CORE_VERSION`
  changes).
- `boot-check.php` — generic pass every milestone can rely on already
  working: production boot succeeds against a real database, every
  module's tables exist, and a second boot is idempotent.
- `checklists/` — one file per milestone's specific runtime checklist
  (e.g. `milestone2.php`, `milestone3.php`). These are permanent
  regression checks, not one-off scripts — a later milestone must not
  silently break an earlier one's frozen behavior, and `verify-runtime.sh`
  with no arguments runs all of them every time.

## Why WordPress core is fetched, not vendored

`wp-includes/class-wpdb.php` and `wp-admin/includes/upgrade.php` are
GPL-licensed WordPress core files, ~1000+ lines of third-party source
that would go stale in this repo the moment WordPress core changes.
`verify-runtime.sh` fetches the pinned `WP_CORE_VERSION` from
`raw.githubusercontent.com/WordPress/WordPress` into a gitignored
`.runtime-cache/` directory the first time it's needed, then reuses the
cached copy — same principle as not vendoring Composer dependencies
into git.

## Adding a checklist for a new milestone

1. Copy the shape of `checklists/milestone3.php`: `require
   __DIR__ . '/../harness-bootstrap.php'`, boot the plugin, resolve
   real services from the container, assert against real database
   state — never a fake/mock, that's what `tests/` is for.
2. Save it as `checklists/<name>.php`.
3. Run just it via `./scripts/verify-runtime.sh <name>`, or leave it to
   run automatically with everything else via `./scripts/verify-runtime.sh`
   (no arguments).
4. Reference the resulting pass in that milestone's
   `docs/verification/*-runtime-verification.md` report.

## What this does NOT replace

A real-database harness proves the logic is correct against an
equivalent database — it does not prove the actual hosting target (PHP
build, MySQL version, hosting-specific restrictions) behaves the same
way. A smoke test on the real hosting target (Hostinger, for this
project) before freezing a milestone remains part of the process; see
the runtime verification reports for the established pattern of running
both together.

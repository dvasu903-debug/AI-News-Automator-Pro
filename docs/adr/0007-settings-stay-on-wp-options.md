# ADR-0007: Settings Stay on `wp_options`, Not a New Table

**Status:** Accepted · **Module:** 3

## Context

Storage's mandate was "avoid overloading `wp_options`," which could be over-read as "nothing belongs in `wp_options`." Admin settings (small, read-heavy, infrequently written, form-driven config) don't share the access pattern that motivated the durable tables (logs, audit, queue — high write volume, need for indexed querying).

## Decision

`SettingsRepository` wraps `wp_options` behind `SettingsRepositoryInterface` — no dedicated settings table. This is still a proper repository (no code outside it calls `get_option`/`update_option` for plugin settings), but the backing store is what `wp_options` is actually built and optimized for.

## Consequences

- No schema/migration overhead for what is fundamentally small key-value config.
- WordPress's own object-cache behavior for options is inherited for free.
- If a future module's settings genuinely need querying/indexing at volume (unlikely for admin-form config), that would be a new, deliberate ADR — not a silent default.

## Alternatives Considered

- **A generic `ana_settings` table.** Rejected: no query pattern actually requires it, and it would be schema overhead solving a problem that doesn't exist for this data shape.

# Deployment Notes

Operational facts about deploying this plugin that aren't architectural
decisions (those live in `docs/adr/`) but have caused real, reproducible
production issues. Keep entries short — one per gotcha, dated, with the
concrete symptom and fix.

## Replacing a live plugin directory does not re-trigger activation

**Discovered:** 2026-07-24, during the Module 9 (SEO Engine) Hostinger
smoke test on `autocutai.in`.

**Symptom:** After replacing an already-active plugin's directory with a
fresh `git clone` (e.g. to fix a stale/non-git checkout), newer code's
database migrations never run, even though the plugin shows as active
and boots without error. In this case: `wp_ana_draft_seo` (Publishing
Milestone 1's table) didn't exist, causing
`StorageException: Insert into "wp_ana_draft_seo" failed: Table ...
doesn't exist` the first time Module 9's code tried to read/write it.

**Root cause:** WordPress only fires `register_activation_hook()`'s
callback on a genuine inactive→active transition
(`src/Core/Activator.php`, wired in `ai-news-automator-pro.php`). If the
plugin's active flag in `wp_options` survives a directory swap — because
the files changed but the plugin was never actually deactivated — then
`wp plugin activate <slug>` is a no-op ("Plugin already active", no hook
fires). This plugin does have a self-healing migration check on every
`plugins_loaded` (`hasPending()`/`migrate()` in each module's
`ServiceProvider::boot()`), but it only catches migrations added *after*
the site's last real activation; a site whose only real activation
predates a given migration file needs an actual activation transition to
pick it up, not just another boot cycle.

**Fix:** Force a real activation transition:

```bash
wp plugin deactivate ai-news-automator-pro
wp plugin activate ai-news-automator-pro
```

`Deactivator::deactivate()` is non-destructive (pauses hooks, calls
`flush_rewrite_rules()`, touches no table), so this is safe on a live
site. Reactivating fires `register_activation_hook()` for real, which
calls every `ActivatableInterface` provider's `activate()` —
unconditionally running `MigrationRunner::migrate()` against the full
current migration manifest, independent of the self-heal path's
`hasPending()` gating.

**Verify before/after** with direct evidence, not assumption:

```bash
wp db query "SELECT version, description, applied_at FROM $(wp db prefix)ana_schema_migrations ORDER BY id"
```

**When this applies:** Any time a live plugin directory is replaced
in-place (fresh clone, rsync, manual file copy) without an explicit
`wp plugin deactivate` first, and the codebase has gained new migrations
since the site's last real activation.

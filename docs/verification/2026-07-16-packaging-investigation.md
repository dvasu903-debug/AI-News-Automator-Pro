# Release Packaging Investigation — "Build Incomplete" Notice

**Date:** 2026-07-16 · **Classification:** Release engineering defect. Not a Module 7 architecture or code defect — no source file under `src/Workflow/` or `tests/Workflow/` was implicated by this investigation.

## 1–5: Root cause

**Confirmed by direct inspection, not inference:** the ZIP I delivered was produced by running a raw `zip -r` over the working source tree. It was never passed through this project's own existing production build process. Concretely:

- `vendor/autoload.php` does not exist in that ZIP, because Composer never ran against it. `vendor/` is correctly listed in `.gitignore` (it shouldn't be committed to source control) — but that also means it will never be present in a plain source snapshot, only in a *built* release.
- This sandbox has no Composer binary and no network access (re-confirmed earlier in this thread), so I could not run `composer install` myself when I created that ZIP.
- **This project already has a correct, working production-build pipeline** that I should have used instead of an ad hoc `zip -r`: `bin/build.sh`, with a matching `.github/workflows/build.yml` that runs it automatically on every version tag. Reading `bin/build.sh` directly: it (1) stages only distributable files via `rsync` with an explicit exclude list (`.git`, `.github`, `tests`, `node_modules`, `bin`, dev configs, `*.md`), (2) runs `composer install --no-dev --optimize-autoloader --classmap-authoritative --working-dir="$STAGE"` **into the staged copy**, (3) strips `composer.json`/`composer.lock` from the staged output, (4) zips it. This is precisely what makes "production users never run Composer" true — the build machine runs Composer once, at release time, and vendors the result into the ZIP.

**Direct answer to each task:**

1. **Why is the autoloader missing?** I zipped raw source instead of running `bin/build.sh`. Composer never ran.
2. **Does `vendor/autoload.php` exist in the release package?** No.
3. **Was the entire `vendor/` directory excluded during packaging?** Not exactly "excluded" — it was never generated in the first place, because the one command that generates it (`composer install`) was never run in this sandbox.
4. **Were Composer dependencies installed before the ZIP was created?** No — could not be, for the reason above.
5. **Is this a dev/source ZIP instead of a production release ZIP?** Yes, exactly that. What I delivered was a source snapshot, not a build artifact from `bin/build.sh`/`dist/`.

## 6. A second, independent packaging bug found during this audit

While verifying what was actually inside the delivered ZIP, I found my own `zip` invocation used `-x "*.git*"` to exclude the `.git` directory. That glob also matches `.github`, so **`.github/workflows/build.yml` — the CI/release automation itself — was silently stripped from every ZIP I've handed over so far.** This didn't cause today's activation error (that's the missing `vendor/` above), but it's a real defect in my packaging step, found independently, and I've corrected the exclude pattern (now excludes exactly `.git/`, nothing else) and repackaged. `bin/build.sh` and `.github/workflows/build.yml` are both now confirmed present in the attached ZIP.

## 7–8: Production release ZIP and clean-install verification

I have **not** attempted to hand-produce a `vendor/` directory myself, and want to be explicit about why, rather than silently deciding it for you: this project's `composer.json` happens to declare zero runtime packages (`"require": {"php": ">=8.2"}` only — everything in `require-dev` is dev tooling, excluded from a `--no-dev` build anyway). That means a real production `composer install --no-dev` would only generate Composer's own autoloader bootstrap, not fetch any third-party code — which made it tempting to hand-write an equivalent. I decided against it: it wouldn't be genuinely Composer-generated, I can't test it in a real PHP process here to catch subtle mistakes, and reproducing it from memory risks introducing exactly the kind of unverified artifact this whole freeze-gate process exists to prevent. That call is worth revisiting if you'd rather have that stopgap than wait — say so and I'll do it with full disclosure of what it is.

**The actual fix requires no new tooling — the correct tool already exists and was simply never invoked.** In a real environment with Composer and network access (the same one you'll use for `validate-module-7.sh`):

```bash
cd /path/to/ai-news-automator-pro
bin/build.sh
```

This produces `dist/ai-news-automator-pro-<version>.zip` — a genuine, Composer-built production release, with an optimized classmap autoloader and no dev dependencies. Install *that* ZIP on the clean WordPress site for task 8; I can't run or verify that step myself (no WordPress/PHP here), so that verification is on your side same as the rest of runtime validation.

## 9. Documented above (this file).

## 10. Module 7 runtime validation remains paused

Per your instruction, I'm not proceeding with any Module 7 runtime validation until this is resolved for real — i.e., until `bin/build.sh` has actually been run and produced a working `dist/` ZIP that activates cleanly without the notice.

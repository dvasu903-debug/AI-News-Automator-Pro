# Roadmap — AI Publishing Engine (AI News Automator Pro)

Three-layer naming: Architecture (AI Publishing Engine), Product (AI News
Automator Pro), Vertical (News).

## Module status

| Module | Name | Status |
|---|---|---|
| 1 | Core | Frozen |
| 2 | Security | Frozen |
| 3 | Storage | Frozen (one authorized post-freeze fix — see below) |
| 4 | AI Provider Engine | Frozen |
| 5 | Source Connectors | Frozen |
| 6 | Research Engine | Frozen |
| 7 | Workflow Engine | **Frozen — 2026-07-21** |
| 8 | Publishing Engine | Not started |

## Module 7 — freeze record

**Status: FROZEN — 2026-07-21.**

Module 7 is frozen based on successful validation in the
production-equivalent Hostinger/LiteSpeed environment. Cross-host
validation (e.g., Apache/Nginx with different PHP/MySQL versions) is
recommended before the first commercial GA release.

Module 7 completed full validation: automated checks (PHP syntax,
PHPUnit, PHPCS — `scripts/validate-module-7.sh` sections 0–6) and manual
runtime validation against a real WordPress + MySQL + WP-Cron
environment (sections 7–15, all 9 items PASS with direct evidence). Full
evidence trail: `docs/verification/2026-07-21-module-7-runtime-validation.md`.

Two genuine defects were found and fixed during runtime validation —
both reachable only through real, full-container execution, neither
catchable by the unit test suite's fake-DB harness:

1. `ActionRegistryInterface` was container-bound `bind()` instead of
   `singleton()` — every workflow action type was unusable at runtime
   despite passing all unit tests.
2. `QueueRepository::claimNextForWorker()` (Module 3, frozen) had no
   stale-claim recovery — a crashed worker orphaned its job forever.
   Fixed as an authorized post-freeze Storage change, unit-tested, and
   live-reverified on real orphan state; see
   `docs/verification/authorized-frozen-changes.txt`.

**Permanent release documentation** (kept as the audit trail template
for every future module): validation report, `authorized-frozen-changes.txt`,
`CHANGELOG.md`, `RELEASE_NOTES.md`.

## Module 8 — Publishing Engine (next)

Scope, architecture, and design to be captured in
`MODULE_8_PUBLISHING_ENGINE_DESIGN.md` before implementation begins, per
the project's standing audit-before-code discipline. High-level scope
(from the approved brief): draft lifecycle, WordPress post integration,
the research → generation → validation → publish → post-process → events
pipeline, publishing profiles, editorial approval modes, a defined set of
publishing events, and REST controllers — built as an independent module
integrating with the frozen Modules 1–7 without modifying them.

## Guiding principles (unchanged since Module 1)

Truth before speed. Quality before quantity. Security by design.
Enterprise architecture. Audit before code. No bundled sprints. No
modification of frozen modules without an explicit, documented,
narrowly-scoped exception.

# ADR-0005: Queue / Job History Table Split

**Status:** Accepted · **Module:** 3

## Context

A single queue table that accumulates every job forever (pending, processing, and years of completed history) becomes slow to scan for "find the next pending job" — even with indexes — due to row bloat and, on InnoDB, MVCC/undo overhead from a table under constant insert/update/delete churn.

## Decision

`ana_queue` holds only active jobs (pending/processing/delayed) — small, fast, indexed on `(status, run_after, priority)`. The moment a job completes, fails permanently, or is cancelled, `QueueRepository` moves its row — inside a transaction — into `ana_jobs`, an append-mostly historical ledger safe to grow large and subject to retention pruning. Both tables share the same logical field shape; this is one entity partitioned by lifecycle stage for performance, not two independent schemas.

## Consequences

- The hot "claim next job" query never has to scan historical rows, regardless of how much history accumulates.
- `JobHistoryRepositoryInterface`'s write path is intentionally narrow (`recordFromQueue()`, called only by `QueueRepository`) — no other code writes to `ana_jobs` directly, keeping the queue-to-history transition atomic and in one place.
- Retention pruning targets `ana_jobs` specifically; `ana_queue` should always stay small on its own via normal job completion.

## Alternatives Considered

- **One table with a status column.** Rejected: the exact problem this ADR exists to avoid — a single table under both hot-path churn and long-term accumulation.

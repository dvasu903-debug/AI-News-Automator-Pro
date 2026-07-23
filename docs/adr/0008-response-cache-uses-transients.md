# ADR-0008: Response Caching Uses Transients, Not a Database Table

**Status:** Accepted · **Module:** 4

## Context

AIManager needed a response cache to avoid repeated identical provider calls. This data is expendable by nature — losing a cached response costs a re-computation, never correctness.

## Decision

`TransientResponseCache` uses WordPress transients (object-cache-backed when available), keyed by a hash of everything that affects the response (model, messages, schema) and nothing that doesn't (no correlation id). No new table. This is the same category of decision as Security's `TransientRateLimiter` (Module 2) and Storage's Settings-on-options (ADR-0007): match the storage mechanism to the data's actual durability requirement.

## Consequences

- A cache hit costs zero: no rate-limit check, no provider call, no cost recorded — genuinely free.
- Cache entries can be silently evicted by the object cache under memory pressure with no correctness impact, only a cache-miss cost.
- No retention policy needed for this data — transient TTLs handle expiry natively.

## Alternatives Considered

- **A dedicated `ana_ai_response_cache` table with TTL column.** Rejected: adds schema/migration/retention-policy overhead for data that is, by definition, safe to lose at any time.

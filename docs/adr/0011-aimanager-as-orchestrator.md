# ADR-0011: AIManager Is the Single Orchestration Layer

**Status:** Accepted · **Module:** 4

## Context

Every future module needing AI work (Pipeline, SEO, Research, ...) needs one stable thing to depend on — not a provider, not even `AIProviderInterface` directly, since capability, health, and configuration all vary per request.

## Decision

`AIManager` is the only class business logic depends on for AI work. It orchestrates, in order: request validation (shape + capability), response-cache lookup, rate limiting, retry execution, failover, cost calculation, Storage recording, and event dispatch — delegating discovery to `ProviderRegistry` (never instantiating a provider itself) and translation to whichever provider `ProviderRegistry` resolves.

## Consequences

- Business logic's dependency surface for AI work is one class and a handful of DTOs — never a concrete provider, never `AIProviderInterface`.
- `AIManager`'s orchestration logic is fully unit-testable against `FakeChatProvider` with zero HTTP, exactly because it never talks to a vendor directly.
- Every future capability (image generation, embeddings) that gets wired through `AIManager` automatically gets the same retry/failover/caching/cost/event behavior "for free" the same way `chat()` does — the orchestration shell (`executeWithFailover()`) is written generically for this reason.

## Alternatives Considered

- **Business logic resolves providers directly via `ProviderRegistry`, applying its own retry/caching.** Rejected: pushes reliability logic back out to every caller, the exact duplication ADR-0010 exists to prevent, just at a different layer.

# ADR-0010: Provider Adapters Are Thin Translators

**Status:** Accepted · **Module:** 4

## Context

Retry, timeout, failover, caching, rate limiting, and cost recording could each live inside every provider class, or in one place above them.

## Decision

A provider adapter's only job is translating this module's provider-agnostic DTOs (`ChatRequest`/`ChatResponse`) to and from one vendor's wire format, over an SSRF-guarded HTTP call (`AbstractHttpProvider`, shared HTTP mechanics + default error classification). No provider implements retry loops, failover awareness, caching, or cost calculation — that's `AIManager`'s job (ADR-0011).

## Consequences

- Every provider stays small and easy to review — `ClaudeProvider`/`GeminiProvider`/`OpenAiCompatibleProvider` each contain only request-building and response-parsing logic.
- Reliability behavior (retry, failover, caching) is implemented and tested exactly once, in `AIManager`/`RetryExecutor`/`FailoverChain`, rather than once per provider.
- Adding a new provider never means re-implementing retry/failover semantics — a new adapter automatically inherits correct reliability behavior just by being registered.

## Alternatives Considered

- **Provider-internal retry.** Rejected: duplicates the same backoff/classification logic across every provider class; a bug fix would need to be applied N times.

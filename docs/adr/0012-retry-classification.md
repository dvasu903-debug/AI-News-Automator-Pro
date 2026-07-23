# ADR-0012: Retry Classification — Not Every Failure Is Retried

**Status:** Accepted · **Module:** 4

## Context

A naive retry-on-any-exception policy retries validation errors, bad credentials, and exhausted quotas — none of which can succeed by trying again, and retrying them wastes time, money, and (for quota) makes the underlying problem worse.

## Decision

Every AI-module exception carries an `AIErrorType` (`Validation`, `Authentication`, `RateLimited`, `Quota`, `ProviderOutage`, `UnsupportedCapability`, `Unknown`). Only `RateLimited` and `ProviderOutage` are classified retryable. `AbstractHttpProvider::classifyHttpError()` maps HTTP status codes to these categories once, shared by every provider, rather than each provider inventing its own classification.

## Consequences

- `RetryExecutor` never retries a request that structurally cannot succeed on retry — verified by `RetryExecutorTest`'s per-category coverage.
- `FailoverChain` is only ever consulted after a *retryable* failure exhausts its retry budget (`ProviderUnavailableException`) — a validation/auth/quota error fails fast without wasting a failover attempt against a provider that would hit the identical problem.
- Vendor-specific error classification nuance (e.g. distinguishing rate-limit from quota-exhaustion via a body field) is a per-provider override point (`classifyHttpError()`), not a rewrite of the shared logic.

## Alternatives Considered

- **Retry every failure up to N times.** Rejected: the exact anti-pattern the "do not retry every failure" requirement exists to prevent — wastes retry budget and money on unrecoverable errors.

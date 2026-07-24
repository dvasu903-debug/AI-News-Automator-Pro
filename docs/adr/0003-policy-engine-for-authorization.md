# ADR-0003: Policy Engine for Authorization

**Status:** Accepted · **Module:** 2

## Context

The old pre-rebuild plugin scattered `current_user_can()` checks across files with no audit trail, no way to compose multiple rules, and no extension point for a future module to add its own authorization logic (e.g. an IP allowlist, a future 2FA gate).

## Decision

A single flow: **Permission → Policy → Decision → Audit → Event.** `CapabilityGate` is the only sanctioned authorization entry point (`allows()`/`authorize()`). It delegates to `PolicyEngine`, which runs every registered `PolicyInterface` implementation (collected via container tagging under `security.policies`) for a given ability, resolves Allow/Deny/Abstain with explicit-deny-wins and default-deny, then `CapabilityGate` audits the decision and emits `PermissionDeniedEvent` on denial.

## Consequences

- No business logic calls `current_user_can()` directly — verified by the Architecture Verification Report's grep sweep.
- Future modules add authorization rules by registering a new `PolicyInterface` implementation, never by modifying `CapabilityGate` or `PolicyEngine`.
- Every authorization decision is audited automatically, which is also what makes a *bypass* attempt detectable (an authorization decision with no matching audit entry is itself a signal).
- Default-deny means an ability nobody explicitly allows is refused, not accidentally permitted — the safe failure direction.

## Alternatives Considered

- **Flags on one interface** (`supportsCapability(string): bool` checked ad hoc). Rejected: turns into runtime string-matching scattered through business logic, the exact pattern this ADR exists to avoid.

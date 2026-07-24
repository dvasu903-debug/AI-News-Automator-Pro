# ADR-0013: Failover Considers Capability, Health, Priority, and Admin Policy

**Status:** Accepted · **Module:** 4

## Context

A naive failover picks "the next registered provider" — which could be structurally incapable of the request (failing over a vision request to a text-only model), already known to be down, or a provider the administrator has deliberately excluded.

## Decision

`FailoverChain::nextEligible()` filters candidates through all four factors in order: capability (`ProviderRegistry::allImplementing()` — structural eligibility), administrator exclusion (`ai.failover.excluded_providers` config), health (`healthCheck()->isEligibleForFailover()`), then sorts remaining candidates by configured priority (`ai.failover.priority`).

## Consequences

- A failover target is never structurally wrong for the request (verified by `FailoverChainTest::test_selects_only_capability_eligible_provider`).
- An administrator can hard-exclude a provider from ever being chosen as a failover target (e.g. a provider under a separate incident) without disabling it outright.
- A provider reporting `Unavailable` from its own rolling health signal is skipped automatically, rather than being retried into failing again.

## Alternatives Considered

- **Priority order only.** Rejected: doesn't answer "do not fail over blindly" — priority alone says nothing about whether the target can even handle the request or is currently healthy.

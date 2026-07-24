# ADR-0014: Capability Resolution Is Provider + Model, Never Provider Alone

**Status:** Accepted · **Module:** 4

## Context

"Does this provider support vision" is not always a single true/false fact — within "OpenAI," `gpt-image-2` doesn't support vision even though the provider generally does; within "Ollama," vision depends entirely on which local model the site operator pulled.

## Decision

Two layers of capability truth: `instanceof VisionProviderInterface` (etc.) answers *coarse* structural eligibility (does this provider class's code know how to handle vision at all — used for failover routing). `ModelCatalogInterface::capabilitiesFor(providerId, model)`, when the model is known, is the *authoritative*, more specific check — and it wins when it disagrees with the coarse check. `AIRequestValidator` consults both, in that order, before any provider is contacted.

## Consequences

- A request for an unsupported capability on a specific model is rejected before an expensive/costly HTTP call, not after a confusing vendor error.
- An unknown model (not yet in the catalog) degrades gracefully to the coarse provider-level check rather than blocking the request outright — verified by `AIRequestValidatorTest::test_unknown_model_skips_catalog_check_but_still_requires_structural_capability`.
- `ModelCatalogInterface`'s data quality directly determines validation precision — this is part of why the catalog is designed to be refreshable (see the module's "avoid hard-coded permanent model lists" requirement) rather than a permanent static list.

## Alternatives Considered

- **Provider-level capability only** (`ProviderCapabilities`, no per-model check). Rejected: this was the explicit thing the approved requirement said not to do — "capabilities must be determined by provider + selected model, not provider alone."

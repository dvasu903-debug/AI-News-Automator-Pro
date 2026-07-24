# ADR-0009: OpenAI-Compatible Provider Consolidation

**Status:** Accepted · **Module:** 4

## Context

Seven named example providers (Claude, OpenAI, Gemini, OpenRouter, DeepSeek, Grok, Ollama) could each get their own adapter class. Before designing, each vendor's *current* documentation was checked directly (not assumed from training data, since these APIs evolve fast) — OpenRouter, DeepSeek, Grok, and Ollama's OpenAI-compatible endpoint all confirmed, in their own docs, that their chat API matches OpenAI's `/chat/completions` schema closely enough that "most SDKs work by just swapping the base URL" (OpenRouter's own phrasing).

## Decision

One class, `OpenAiCompatibleProvider`, parameterized by a `ProviderConfig` value object (base URL, auth header scheme, capability flags), instantiated once per vendor: OpenAI itself, OpenRouter, DeepSeek, Grok, and Ollama. `ClaudeProvider` and `GeminiProvider` remain dedicated classes because their request/response *shapes* genuinely differ (Claude's content-block messages and top-level `system` field; Gemini's `parts`/`contents` and `x-goog-api-key` header) — collapsing those into the generic class would be forcing the wrong abstraction, not simplifying.

## Consequences

- Five vendors, one class + five config objects instead of five near-duplicate classes.
- Adding another genuinely OpenAI-compatible vendor is a `ProviderConfig` entry in `AIServiceProvider`, not a new class — directly serving "add a provider without changing business logic."
- If a sixth vendor's API drifts from the OpenAI shape in some meaningful way, that specific field needs a config flag (as `DeepSeek`'s `supportsVision: false` already demonstrates) or, if the drift is structural rather than a capability flag, a dedicated class — the same judgment call made for Claude/Gemini.

## Alternatives Considered

- **Seven separate classes.** Rejected: ~70% duplicated request/response mapping code across four vendors that are structurally identical.
- **One class for all seven, including Claude/Gemini, with per-vendor `if` branches.** Rejected: turns one class into a vendor-shape dispatcher, harder to test and reason about than two small dedicated classes plus one generic one.

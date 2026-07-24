# Architecture Decision Records

Index of major architectural decisions for the AI Publishing Engine. Each ADR records context, the decision, and consequences — the goal is that a future contributor (including a future instance of whoever is building this) can see *why* something is the way it is without re-deriving it or accidentally re-litigating it.

Format: lightweight (Context / Decision / Consequences / Alternatives Considered), not the heavier Michael Nygard template with numbered options — kept consistent and quick to write/read across ~15 records rather than exhaustive per-record.

| # | Title | Module |
|---|---|---|
| [0001](0001-remove-plugin-singleton.md) | Remove the Plugin Singleton | 1.1 |
| [0002](0002-container-registration-order-as-override-mechanism.md) | Container Registration Order as the Rebinding Mechanism | 1.1 / 3 / 4 |
| [0003](0003-policy-engine-for-authorization.md) | Policy Engine for Authorization | 2 |
| [0004](0004-no-foreign-key-constraints.md) | No Foreign Key Constraints | 3 |
| [0005](0005-queue-job-history-table-split.md) | Queue / Job History Table Split | 3 |
| [0006](0006-storage-frozen-but-reusable.md) | Storage Is Frozen From Modification, Not From Reuse | 3 / 4 |
| [0007](0007-settings-stay-on-wp-options.md) | Settings Stay on `wp_options`, Not a New Table | 3 |
| [0008](0008-response-cache-uses-transients.md) | Response Caching Uses Transients, Not a Database Table | 4 |
| [0009](0009-openai-compatible-provider-consolidation.md) | OpenAI-Compatible Provider Consolidation | 4 |
| [0010](0010-thin-provider-adapters.md) | Provider Adapters Are Thin Translators | 4 |
| [0011](0011-aimanager-as-orchestrator.md) | AIManager Is the Single Orchestration Layer | 4 |
| [0012](0012-retry-classification.md) | Retry Classification — Not Every Failure Is Retried | 4 |
| [0013](0013-failover-considers-four-factors.md) | Failover Considers Capability, Health, Priority, and Admin Policy | 4 |
| [0014](0014-capability-resolution-provider-plus-model.md) | Capability Resolution Is Provider + Model, Never Provider Alone | 4 |
| [0015](0015-bounded-naming-separation.md) | Bounded Naming Separation — Engine vs. Product vs. Vertical | Cross-cutting |
| [0016](0016-modules-own-temporary-infrastructure.md) | Modules Own Temporary Infrastructure Until a Shared Abstraction Exists | Cross-cutting (Module 5) |
| [0017](0017-extraction-trigger-met-but-deferred.md) | Extraction Trigger Met But Deferred | 6 (applies to Module 7) |
| [0018](0018-publishing-milestone-3-scope-and-planner-interpretation.md) | Publishing Milestone 3 Scope and "Planner" Interpretation | 8 |
| [0019](0019-ai-generation-pipeline-scope-and-trust-boundary.md) | AI-Generation Pipeline Scope and Trust Boundary | 8 |
| [0020](0020-seo-engine-trust-boundary-and-extensibility-seam.md) | SEO Engine Trust Boundary and Extensibility Seam | 9 |

New ADRs are added here as Module 6+ introduces its own non-obvious, expensive-to-reverse decisions — not for every choice, only ones a future maintainer would otherwise have to re-derive from scratch or might accidentally reverse without realizing it was deliberate.

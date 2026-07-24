# Module 5: Source Connectors Engine

Discovers, normalizes, and deduplicates candidate content items from RSS/Atom feeds, JSON news APIs, crawled websites, and XML sitemaps. Full design rationale in `../../planning/SOURCE_CONNECTORS_ENGINE_DESIGN.md`. This README documents what was built and verified.

## Explicit scope boundary

This module discovers and normalizes. It does **not** decide what becomes a published article, does **not** call `AIManager`, and does **not** call `ArticleRepositoryInterface::createDraft()`. It ends at `ItemDiscoveredEvent` — Research (6) and Pipeline (8) pick up from there.

## Architecture

```
SourceSyncScheduler       → the narrow, Sources-scoped cron dispatcher (ADR-0016)
SourceConnectorRegistry   → discovery (mirrors AI's ProviderRegistry exactly)
Connector (Rss/JsonFeed/  → translation: external format -> NormalizedItem
  WebCrawler/Sitemap)
FetchSourceJobHandler /   → orchestration: validate -> dedup -> event, per queue job
  CrawlUrlJobHandler
Storage                   → SourceRepositoryInterface (reused), new SourceItemRepository (dedup)
Security                  → OutboundHttpValidator, RateLimiterInterface, SecretsProviderInterface
```

## Connectors

| Type | Class | Notes |
|---|---|---|
| `rss` | `RssConnector` | Handles RSS 2.0 and Atom. **Deliberately does not use WordPress's `fetch_feed()`/SimplePie** — that makes its own HTTP request outside Security's guard. Raw XML is fetched via `AbstractHttpConnector`, then parsed locally with `SimpleXMLElement` (no network call during parsing). |
| `json_feed` | `JsonFeedConnector` | **Field-mapping-configured**, not a same-wire-format consolidation like AI's `OpenAiCompatibleProvider` — no shared standard exists across news APIs. Config declares `items_path` + per-field dot-notation paths. API keys resolved via `SecretsProviderInterface`, never stored in config JSON directly. |
| `web_crawler` | `WebCrawlerConnector` | Fetches one listing page, extracts links via `DOMDocument`. Robots.txt is checked before the seed URL is ever fetched — unconditional, no bypass. Does not recursively follow links (explicit scope boundary — deeper crawling is a future module's job once an item is worth it). |
| `sitemap` | `SitemapConnector` | XML sitemap + one level of sitemap-index nesting (bounded — deeper recursion is deliberately unsupported). Robots.txt checked for the root sitemap *and* every child sitemap URL individually. |

All four extend `AbstractHttpConnector`, which owns SSRF-guarded HTTP (via Security's `OutboundHttpValidator`), default HTTP-status error classification into `SourceFetchErrorType`, and per-domain rate limiting (Security's `RateLimiterInterface`) — no connector calls `wp_remote_*` or the HTTP validator directly.

## Deduplication (Approved Decision 1 — Option B implemented)

New table `ana_source_items`, exactly the approved minimal columns: `source_id`, `fingerprint`, `first_seen`, `last_seen`, `status`. No article content, no duplicate storage. Fingerprint is `sha256(guid ?? url)` — a feed-supplied GUID is preferred (survives republish with a changed URL), falling back to the URL when absent.

**A rejected item is a permanent duplicate.** `isDuplicate()` returns true for *any* existing fingerprint row regardless of status — including `rejected`. This is the literal, intended reading of "prevent reprocessing rejected items": once an item fails this module's own validation, it is never re-attempted, even if a later sync would see it differently (e.g. a feed fixing a previously-empty title). Documented here explicitly as a known characteristic, not an oversight.

`SourceItemRepository` reuses Storage's `AbstractRepository`/`BatchPurger` directly (ADR-0006) — one new migration, zero Storage file changes, and the table participates in the same batched-retention pattern as Logs/Audit/Job-History.

## Retry logic (Approved Decision 2)

`SourceRetryExecutor` — a single concrete class, no `RetryPolicyInterface` abstraction layer, scoped to `SourceFetchErrorType`/`SourceFetchException`. Duplicates AI's exponential-backoff *algorithm*, not its *architecture* — see ADR-0016. Only `NetworkTimeout` and `ServerError` are retryable; `NotFound`, `Forbidden`, `RobotsDisallowed`, and `MalformedContent` fail fast.

## Scheduling (Approved Decision 3)

`SourceSyncScheduler` — one WP-Cron hook (`ana_sources_sync_tick`, every 5 minutes), scoped only to `source.fetch`/`source.crawl`. Each tick: (1) enqueues due sources via `SourceRepositoryInterface::dueForFetch()` + `QueueRepositoryInterface::enqueue()`, (2) claims and processes a small batch (5) of its own job types.

**Important, verified safety property:** `QueueRepositoryInterface::claimNextForWorker()` does not and cannot filter by job type (confirmed directly against `QueueRepository`'s implementation — Storage is frozen, this can't change there). Once a future module enqueues its own job types into the same shared queue, this scheduler's claim call could grab one of theirs. `processQueuedBatch()` handles this explicitly: any claimed job whose type isn't `source.fetch`/`source.crawl` is immediately released back to pending via `release()` — never marked failed, never processed. This is what makes "scoped only to source.fetch/source.crawl" a real behavioral guarantee rather than a docblock claim.

## JSON Feed Connector field mapping (Approved Decision 4)

One `JsonFeedConnector` class, configured per source via `SourceRecord::$config`: `url`, `items_path` (dot-notation, e.g. `"response.articles"`), `title_field`/`url_field`/`published_field`/`summary_field`/`author_field`/`guid_field` (each dot-notation, sensible defaults), and optionally `api_key_header`/`api_key_secret_key`/`api_key_format` for authenticated APIs.

## No duplicate architecture (ADR-0016)

`SourceRetryExecutor` and `SourceSyncScheduler` are documented, in code and in ADR-0016, as temporary and narrow — extraction candidates once a *third* module needs materially the same capability, not before.

## Migrations

One migration, `20260714200001_CreateSourceItemsTable`, reusing Storage's `AbstractMigration`/`SchemaBuilder` (not modified). Applied through the *same* `MigrationRunner` singleton Storage registered — no new runner instance, since `migrate()` takes its migration list as a per-call parameter (the same technique AI used for its own two tables).

## Events

`SourceFetchStartedEvent`, `SourceFetchCompletedEvent`, `SourceFetchFailedEvent`, `ItemDiscoveredEvent` (the hand-off point to future modules), `DuplicateItemSkippedEvent`. All extend `SourceEvent` → Core's `AbstractEvent`.

## Security integration

- Every outbound request (feed, robots.txt, sitemap, crawled page) — `Security\Http\OutboundHttpValidator`, no exceptions.
- Per-domain rate limiting — Security's `RateLimiterInterface`, reused (not a new limiter).
- JSON feed API keys — `Core\Contracts\SecretsProviderInterface` (Storage-rebound `CredentialVault`), never in config JSON.
- Admin capability — `Security\Authorization\Capabilities::MANAGE_SOURCES` (already existed, anticipated by Module 2).
- Robots.txt compliance — mandatory, unconditional, before any crawl/sitemap fetch beyond the robots.txt request itself.

## Performance notes

- Incremental sync: every connector filters to items newer than `SourceRecord::lastFetchedAt` where the format supports it (RSS `pubDate`, sitemap `lastmod`, JSON `published_field`).
- Crawl/sitemap fetches are explicitly bounded (`max_links`, `max_urls`, `max_child_sitemaps`) to prevent one sync pass from overwhelming the queue.
- Robots.txt is transient-cached for 24 hours (changes rarely — same posture as AI's response cache).
- Fingerprint table retention is batched (`BatchPurger`, reused), never one unbounded `DELETE`.
- Two-tier retry: HTTP-level retry within one job execution (`SourceRetryExecutor`), separate from job-level retry via Storage's own queue/history machinery when a job fails outright.

## Testing

33 test methods across 6 files, genuinely offline-executable: `RobotsRulesTest` (the longest-prefix-match algorithm — permissive/deny-all/override precedence), `SourceRetryExecutorTest` (per-`SourceFetchErrorType` retry behavior), `SourceConnectorRegistryTest`, `FingerprintDeduplicatorTest` (including the rejected-items-stay-duplicates and per-source-scoping properties), `SourceValidatorTest`, `MetricsBackedReputationScorerTest` (reuses AI's `FakeMetricsRepository` cross-module rather than duplicating an identical fake).

**One test-fidelity limitation worth stating plainly:** AI's `FakeMetricsRepository` (reused here) does not actually discriminate by the `dimensions` array in `increment()`/`counterValue()` — it keys purely by metric name. `MetricsBackedReputationScorerTest`'s assertions are still valid (each test uses a single `source_id`), but the fake does not itself prove per-source metric isolation. Fixing this would mean either enhancing a Module 4 test fixture or building a Sources-specific one; noted here rather than silently worked around.

**Same execution limitation as every module**: no PHP/network runtime in this sandbox. Validated structurally (324 total plugin source files, zero brace/namespace/import failures) and via careful manual reading of every connector and orchestration file — not just automated checks — which is how the four real bugs below were caught.

## Real bugs found and fixed during implementation review

1. **Wrong import namespace.** `SourceFetchErrorType` is declared in `Sources\Retry`, but four files (`AbstractHttpConnector`, `RssConnector`, `SitemapConnector`, `JsonFeedConnector`) imported it from `Sources\Exceptions`. Would have been a fatal "class not found" error. Fixed across all four.
2. **Non-existent enum case.** `AbstractHttpConnector` referenced `SourceFetchErrorType::Transient` three times — no such case exists on the enum (`NetworkTimeout`, `ServerError`, `NotFound`, `Forbidden`, `RobotsDisallowed`, `MalformedContent`, `Unknown`). Would have been a fatal error on the very first network failure or rate-limit hit. Mapped each usage to the correct specific case (connection failure → `NetworkTimeout`, 5xx → `ServerError`, self-imposed rate limit → `NetworkTimeout`).
3. **Inconsistent exception type.** `SourceItemRepository::validate()` threw a Sources-specific `SourceValidationException` where every other repository in the codebase (Storage's own, and AI's `PromptTemplateRepository`) throws Storage's `ValidationException` — breaking any caller that catches validation failures generically. Fixed to match the established convention.
4. **Missing watermark update.** `CrawlUrlJobHandler` never called `SourceRepositoryInterface::recordFetchResult()`, unlike `FetchSourceJobHandler`. Without it, any `web_crawler`-type source would never get its `lastFetchedAt` watermark set, meaning `dueForFetch()` would consider it perpetually due and `SourceSyncScheduler` would re-queue it forever. Fixed to record both success and failure, matching `FetchSourceJobHandler`'s pattern.

A fifth item — the `claimNextForWorker()` job-type-filtering gap — was caught proactively while *designing* `SourceSyncScheduler` (not an existing bug, since the scheduler didn't exist yet) and built with the release-back safety net from the start rather than retrofitted.

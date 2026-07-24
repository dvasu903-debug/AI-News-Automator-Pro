# Module 5: Source Connectors Engine — Audit & Design

> Engine-level module of the **AI Publishing Engine**. No implementation code in this document — design only. Modules 1–4 are frozen (verified `2026-07-14-modules-1-4.md`); this module integrates entirely through their existing public interfaces.

---

## PART 1 — AUDIT

### 1.1 What already exists, anticipating this module

Storage (Module 3) already built the exact persistence seam this module needs:

- **`SourceRepositoryInterface`/`SourceRecord`** — id, name, `type`, `config` (JSON), `enabled`, `lastFetchedAt`, `lastError`, `dueForFetch()`, `recordFetchResult()`. **No new source-metadata table is needed.**
- **`ArticleRepositoryInterface::bySourceUrl(url)`** — already built specifically to check "did we already turn this URL into an article."
- **`QueueRepositoryInterface`/`JobHistoryRepositoryInterface`** — a complete job queue (enqueue, atomic claim, retry-with-backoff at the job level, transactional completion). "Queue all long-running work through the existing queue infrastructure" is satisfied by depending on this directly.
- **`MetricsRepositoryInterface`** — generic atomic counters + time-series events, already the mechanism AI used for `ai.*` metrics. Source reputation and health metrics reuse this the same way.

### 1.2 What genuinely needs designing

| Requirement | New design needed? |
|---|---|
| RSS/XML ingestion, News APIs | Yes — connectors, feed normalization |
| Website crawling + robots.txt | Yes — a real compliance component, not optional |
| Sitemap discovery | Yes — XML sitemap/sitemap-index parsing |
| Source validation | Yes — thin, mirrors AI's `AIRequestValidator` pattern |
| Deduplication | Partially — real trade-off, see §2.1 |
| Source reputation | Mostly reuse — computed from `MetricsRepositoryInterface`, see §3.6 |
| Rate limiting | Pure reuse — Security's `RateLimiterInterface` |
| Retry policies | Partially — real trade-off, see §2.2 |
| Scheduling | Partially — real trade-off, see §2.3 |
| Incremental synchronization | Mostly reuse — `SourceRecord::lastFetchedAt`, already built |
| Source health monitoring | Mostly reuse — same `HealthCheckResult` pattern Security/Storage/AI all used |

Three genuine architectural trade-offs need a decision before design proceeds.

---

## PART 2 — ARCHITECTURE OPTIONS COMPARED

### 2.1 Deduplication: reuse-only vs. a new fingerprint table

**Option A — reuse only.** Before enqueueing a discovered item for processing, check `ArticleRepositoryInterface::bySourceUrl()`. No new table. Bounded by `SourceRecord::lastFetchedAt` (only consider items newer than the last successful sync), which also serves incremental sync — one field, two requirements.

**Option B — a new `ana_source_items` fingerprint table** (URL/GUID hash, first-seen timestamp), tracking every item ever *seen*, not just ones that became a published article.

**Trade-off:** Option A is simpler and reuses more, but an item that was fetched and *rejected* (low quality, failed fact-check — a future Research-module outcome) has no record of having been seen, so it could be re-fetched and re-processed on the next sync, wasting an AI call. Option B catches that case but is a new table this module would own (via its own migrations, reusing Storage's migration mechanics per ADR-0006 — not a new mechanism, just new data).

**Recommendation: Option B**, because "waste an AI call re-processing a known-rejected item" is a real, recurring cost once Modules 6+ exist, and the table itself is small (one row per discovered item, not per full article). Flagged for your confirmation rather than assumed.

### 2.2 Retry logic: reuse AI's RetryExecutor vs. a small parallel implementation

AI's `RetryExecutor`/`RetryPolicyInterface`/`AIErrorType` are real, generic-*shaped* infrastructure, but they're coupled to `AI\Exceptions\AIException` specifically. A source-fetch failure (timeout, 404, malformed feed) isn't an AI-provider failure — reusing AI's exact exception type would be a semantic mismatch, and *generalizing* AI's retry system into Core would mean modifying a frozen module.

**Recommendation:** a small, source-owned `SourceFetchErrorType` enum (mirroring `AIErrorType`'s shape: `Retryable` network/5xx/timeout vs. `NonRetryable` 404/403/malformed-feed) and a small `SourceRetryExecutor`. This is an explicit, bounded exception to "never duplicate infrastructure" — the actual duplicated surface is a small backoff formula (~30 lines), not a subsystem, and the alternative (coupling Sources' HTTP retries to `AIException`, or reopening AI to generalize it) is worse. Flagged for your confirmation as a deliberate, scoped exception rather than something to silently do.

### 2.3 Scheduling: minimal module-scoped cron vs. waiting for a dedicated Scheduler module

The original 15-module roadmap always had a future "Queue & Scheduler" module; Storage absorbed the Queue *persistence* half in Module 3, but no module yet claims queued jobs and runs them on a schedule. Module 5 cannot function without *some* scheduling (enqueue due sources, process their fetch jobs), but building a fully general, plugin-wide job dispatcher is bigger than this module's scope.

**Recommendation:** Module 5 ships a narrow `SourceSyncScheduler` — a single WP-Cron hook that (1) calls `SourceRepositoryInterface::dueForFetch()` and enqueues a fetch job per due source via `QueueRepositoryInterface`, and (2) claims and processes a small batch of *its own* job types (`source.fetch`, `source.crawl`) per tick. This is scoped to Source Connector job types only — not a general-purpose worker. A future dedicated Scheduler module can generalize this pattern plugin-wide (Pipeline, Publishing, etc. job types too), at which point this module's narrow cron hook is a natural retirement candidate. Same "extension point now, fuller implementation later" posture Storage used for `RetentionCleanupJob`.

---

## PART 3 — DESIGN

### 3.1 Folder structure

```
src/Sources/
├── SourcesServiceProvider.php
├── Contracts/
│   ├── SourceConnectorInterface.php       # base: id(), type(), fetch(SourceRecord): FetchResult
│   ├── FeedConnectorInterface.php         # marker: RSS/Atom/XML feeds
│   ├── CrawlConnectorInterface.php        # marker: website crawling
│   ├── SitemapConnectorInterface.php      # marker: sitemap discovery
│   ├── SourceConnectorRegistryInterface.php   # mirrors AI's ProviderRegistryInterface
│   ├── SourceValidatorInterface.php
│   ├── DeduplicationInterface.php
│   ├── RobotsTxtCheckerInterface.php
│   └── SourceReputationInterface.php
├── DTO/
│   ├── NormalizedItem.php                 # provider-agnostic discovered item: title, url, publishedAt, summary, author, guid
│   ├── FetchResult.php                    # list<NormalizedItem> + FetchStatus + error detail
│   └── FetchStatus.php
├── Connectors/
│   ├── AbstractHttpConnector.php          # shared SSRF-guarded HTTP mechanics, mirrors AI's AbstractHttpProvider
│   ├── RssConnector.php                   # RSS + Atom (WordPress's own SimplePie via fetch_feed(), or a minimal XML parser)
│   ├── JsonFeedConnector.php              # config-driven field-mapping for News APIs — see naming note below
│   ├── WebCrawlerConnector.php            # robots.txt-respecting link discovery
│   └── SitemapConnector.php               # XML sitemap + sitemap-index parsing
├── Registry/
│   └── SourceConnectorRegistry.php
├── Robots/
│   └── RobotsTxtChecker.php               # transient-cached, mirrors AI's response-cache posture
├── Validation/
│   └── SourceValidator.php
├── Dedup/
│   └── FingerprintDeduplicator.php        # implements DeduplicationInterface (pending Decision 2.1)
├── Reputation/
│   └── MetricsBackedReputationScorer.php  # computed from Storage's MetricsRepositoryInterface — no new table
├── Retry/
│   ├── SourceFetchErrorType.php           # pending Decision 2.2
│   └── SourceRetryExecutor.php
├── Scheduling/
│   └── SourceSyncScheduler.php            # pending Decision 2.3
├── Jobs/
│   ├── FetchSourceJobHandler.php          # processes a claimed "source.fetch" queue job
│   └── CrawlUrlJobHandler.php
├── Storage/
│   ├── SourcesMigrationManifest.php       # AI-module-pattern: reuses Storage's migration classes, doesn't modify them
│   └── Migrations/
│       └── Migration_..._CreateSourceItemsTable.php   # only if Decision 2.1 = Option B
├── Events/
│   ├── SourceEvent.php
│   ├── SourceFetchStartedEvent.php
│   ├── SourceFetchCompletedEvent.php
│   ├── SourceFetchFailedEvent.php
│   ├── ItemDiscoveredEvent.php
│   └── DuplicateItemSkippedEvent.php
├── Health/
│   └── SourceHealthCheck.php              # reuses Security's HealthCheckResult, 4th module to do so
└── Admin/
    └── SourcesSettingsPage.php
```

**Naming note on `JsonFeedConnector`:** unlike AI's `OpenAiCompatibleProvider` (which relies on a genuine, vendor-confirmed shared wire format), no such standard exists across news APIs (NewsAPI.org, GNews, Mediastack, etc. all shape their JSON differently). `JsonFeedConnector` is a **field-mapping-configured** connector (a config declares "articles are at this JSON path, title is this field, url is this field"), not a same-wire-format consolidation — a deliberately different kind of generalization from Module 4's, named distinctly so the two aren't confused.

### 3.2 Core interfaces (illustrative signatures)

```php
interface SourceConnectorInterface {
    public function type(): string;                    // matches SourceRecord::$type
    public function fetch(SourceRecord $source): FetchResult;
}

interface SourceConnectorRegistryInterface {
    public function register(SourceConnectorInterface $connector): void;
    public function forType(string $type): ?SourceConnectorInterface;
}

interface DeduplicationInterface {
    public function isDuplicate(string $sourceId, NormalizedItem $item): bool;
    public function markSeen(string $sourceId, NormalizedItem $item): void;
}

interface RobotsTxtCheckerInterface {
    public function isAllowed(string $url, string $userAgent): bool;
    public function discoveredSitemaps(string $domain): array; // from robots.txt "Sitemap:" directives
}
```

### 3.3 Robots.txt compliance (required, non-optional)

`RobotsTxtChecker` fetches `/robots.txt` via Security's `OutboundHttpValidator` (same SSRF guard as every other outbound call in the plugin), parses `User-agent`/`Disallow`/`Allow` groups for the plugin's own user agent string, and caches the parsed result via transient (robots.txt changes rarely — same caching posture as AI's response cache, ADR-0008's reasoning applied here). `WebCrawlerConnector` and `SitemapConnector` both consult it before fetching anything beyond the robots.txt request itself. This is treated as a compliance requirement, not a performance optimization — a disallowed URL is never fetched, full stop, regardless of any other setting.

### 3.4 Feed normalization

Every connector returns `list<NormalizedItem>` regardless of source type — RSS `<item>`, a JSON API's article object, and a crawled page's extracted metadata all become the same shape (title, url, publishedAt, summary, author, guid). This is what lets downstream consumption (dedup, article creation) stay connector-agnostic, mirroring how AI's `ChatResponse` stays provider-agnostic regardless of which vendor produced it.

### 3.5 Incremental synchronization

`SourceRecord::lastFetchedAt` (already built) is the watermark. Connectors are expected to filter to items published/modified after that watermark where the underlying format supports it (RSS `pubDate`, sitemap `lastmod`) — reducing both re-fetch cost and the dedup-check surface. `SourceRepository::recordFetchResult()` (already built) updates the watermark on completion.

### 3.6 Source reputation — computed, not stored redundantly

No new column or table. `MetricsBackedReputationScorer` reads `source.fetch_success`/`source.fetch_failure` counters (written via `MetricsRepositoryInterface::increment()`, dimensioned by `source_id` — same mechanism AI used for `ai.*` metrics) and computes a reputation signal (e.g. rolling success ratio) on demand. Keeps reputation as a derived view over existing telemetry, not a second source of truth to keep in sync.

### 3.7 Job types queued through Storage's existing queue

`source.fetch` (one source, one connector call) and `source.crawl` (one URL, robots-checked). Both go through `QueueRepositoryInterface::enqueue()`/`claimNextForWorker()`/`markSuccess()`/`markFailure()` exactly as built in Module 3 — no new queue mechanism.

---

## PART 4 — INTEGRATION VERIFICATION (no duplicated infrastructure)

| Need | Reused from | New code |
|---|---|---|
| Source metadata persistence | Storage `SourceRepositoryInterface`/`SourceRecord` (as-is) | None |
| Duplicate-article check | Storage `ArticleRepositoryInterface::bySourceUrl()` (as-is) | None |
| Job queue | Storage `QueueRepositoryInterface`/`JobHistoryRepositoryInterface` (as-is) | Two job-type handlers |
| Reputation/health metrics | Storage `MetricsRepositoryInterface` (as-is) | None (computed view) |
| Outbound HTTP + SSRF guard | Security `OutboundHttpValidator` (as-is) | None |
| Rate limiting | Security `RateLimiterInterface` (as-is) | None |
| Events | Core `EventDispatcherInterface`/`EventMetadataFactory` (as-is) | 5 new event classes |
| Settings page | Core `AbstractSettingsPage` (as-is) | One new settings page |
| Health check shape | Security `HealthCheckResult` (as-is, 4th module to reuse it) | One new health check class |
| Migrations (if Decision 2.1 = B) | Storage's `Connection`/`MigrationRunner`/`MigrationRecorder`/`AbstractMigration`/`AbstractRepository` (instantiated fresh, not modified) | One new table, this module's own manifest |
| Article creation from a validated item | AI `AIManager` (future call, once Module 6/Research decides an item is worth writing — Module 5 discovers and normalizes, it does not itself decide to publish) | None — explicitly out of this module's scope |

Zero files in `src/Core/`, `src/Security/`, `src/Storage/`, or `src/AI/` require modification. `ModuleManifest` gets one addition (`SourcesServiceProvider::class`, positioned after AI) — the same designed extension point used for every prior module.

**Explicit scope boundary:** Module 5 discovers, normalizes, deduplicates, and validates candidate items. It does **not** decide whether an item becomes a published article, does **not** call `AIManager` for fact-checking or writing, and does **not** call `ArticleRepositoryInterface::createDraft()`. Those are Research (6) and Pipeline (8)'s jobs. Module 5's job ends at "here is a deduplicated, normalized, validated candidate item, queued for the next stage."

---

## PART 5 — SECURITY REVIEW

- Every outbound request (feed fetch, robots.txt, sitemap, crawled page) goes through `Security\Http\OutboundHttpValidator` — no exceptions, verified the same way Module 4's provider HTTP calls were.
- **Crawling is the highest-risk capability this module adds.** A "fetch this URL" primitive driven by admin-supplied source config is exactly the SSRF shape `UrlGuardInterface` exists to block — reused directly, not reimplemented.
- Robots.txt compliance is enforced before any crawl/sitemap fetch beyond the robots.txt request itself — both a legal/ethical requirement and a practical one (a misbehaving crawler is how a site gets the whole plugin's IP blocked).
- A crawled page's content is untrusted input. This module normalizes and stores it (title/url/summary as plain data); it does not execute, render, or evaluate anything from a crawled page. Sanitizing crawled *content* before it becomes part of an AI prompt remains Research (6)'s responsibility (same boundary AI's own security review drew for Sources' output).
- Per-source and per-domain rate limiting (Security's `RateLimiterInterface`, reused) applies to crawling specifically — an unbounded crawl loop against one domain is both a politeness and an abuse-liability problem, distinct from cost-exhaustion (Module 4's concern) but the same underlying primitive.

---

## PART 6 — PERFORMANCE STRATEGY

- Incremental sync (§3.5) bounds both re-fetch volume and dedup-check volume to genuinely new items.
- Long-running work (crawling, large feed processing) is always queued (§3.7), never run inline on a web request — consistent with Storage's queue/history design intent.
- Robots.txt and sitemap results are transient-cached (rarely change) — avoids re-fetching compliance data on every crawl.
- The dedup fingerprint table (if Decision 2.1 = B) is deliberately narrow (hash + timestamp, no full item content) and subject to the same retention-policy pattern Storage established (`RetentionPolicy`/`BatchPurger`, reused via a Sources-owned policy instance).

## PART 7 — TESTING STRATEGY

Same posture as every prior module: fake connectors (`FakeSourceConnector`, mirroring `FakeChatProvider`) for orchestration tests requiring no real HTTP; real robots.txt parsing logic unit-tested against static fixture text (pure function, no network); dedup logic tested against a fake `DeduplicationInterface` backing store; integration tests against real feeds documented as requiring a live environment, not run here. Same honest execution limitation as every module: no PHP/network runtime in this sandbox — written tests are offline-executable, validated structurally, not behaviorally confirmed here.

---

## OPEN DECISIONS FOR YOUR SIGN-OFF

1. **Deduplication: fingerprint table (Option B) vs. reuse-only (Option A)** — §2.1. Recommending B.
2. **Retry logic: small Sources-owned parallel implementation** — §2.2, an explicit bounded exception to "never duplicate infrastructure." Recommending this over coupling to `AIException` or reopening AI.
3. **Scheduling: narrow Sources-scoped cron now, generalize later** — §2.3. Recommending this over waiting for a dedicated Scheduler module that doesn't exist yet.
4. **`JsonFeedConnector` as field-mapping-configured** (not a same-wire-format consolidation like `OpenAiCompatibleProvider`) — confirm this is the right generalization given no shared News-API standard exists, rather than separate classes per vendor.

Waiting for approval before writing any implementation code.

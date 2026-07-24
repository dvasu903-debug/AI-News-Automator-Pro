# Module 2: Security — Audit & Design

> Engine-level module of the **AI Publishing Engine** (platform behind the *AI News Automator Pro* product). Domain-agnostic; shared across all publishing verticals. Identifiers retain the `AINewsAutomator\` namespace and `ana_` prefixes for backward compatibility.

No code in this document. Design frozen here before implementation, per workflow.

## PART 1 — REQUIREMENTS AUDIT

Grouping the 25 objectives into coherent sub-systems, and judging what each realistically needs.

### 1A. Authorization (capability layer + fine-grained permissions)
The old plugin used raw `current_user_can('manage_options')` scattered across files. Requirement: a single `CapabilityGate` that every module calls instead of touching `current_user_can` directly, plus a plugin-specific capability map so an editorial team can have "can approve drafts" separate from "can change settings" — WordPress's built-in caps are too coarse for that. Design decision: define custom capabilities (e.g. `ana_manage_settings`, `ana_approve_content`, `ana_view_analytics`, `ana_manage_security`) mapped to roles at activation, resolved through the gate. The gate is filterable so permissions can be customized without code edits.

### 1B. Request integrity (nonces, CSRF, secure request validation)
WordPress nonces are the native CSRF primitive. Requirement is a `NonceManager` wrapping `wp_create_nonce`/`wp_verify_nonce` with plugin-namespaced actions, plus a `RequestValidator` that combines nonce + capability + (optionally) rate-limit checks into one guard call, so an admin-post or AJAX handler validates everything in one line and fails closed.

### 1C. REST security middleware
REST endpoints need the same guarantees as admin-post actions but through `permission_callback`. Requirement: a `RestSecurityMiddleware` producing permission callbacks that chain capability + nonce (via the `X-WP-Nonce` header, the WordPress REST convention) + rate limiting. `AbstractRestController` from Core will be extended to consume this — but note that's a Core file. Per the freeze rule I will NOT rewrite it; instead Security provides the middleware and later REST controllers opt into it. The one small Core touch: `AbstractRestController` currently has a `requireCapability()` that calls `current_user_can` directly — I'll leave it as-is (backward compatible) and have Security-aware controllers use the middleware instead. Documented, not rewritten.

### 1D. Secrets management (vault, encryption, key rotation, provider abstraction)
This is the highest-stakes piece. Requirement: encrypt API keys at rest so a DB leak doesn't expose them. Design decision on crypto: use **libsodium** (`sodium_crypto_secretbox`), which ships with PHP 8.2 core — no external dependency, authenticated encryption, not the footgun that raw OpenSSL is. The encryption key is derived from WordPress's own secret keys/salts (`wp_salt()`), so it lives in `wp-config.php` (or env), not the database — meaning a DB-only breach yields ciphertext with no key. Key rotation: store a key-version byte with each ciphertext so re-encryption on rotation is possible without guessing which key encrypted what. `CredentialVault` implements Core's existing `SecretsProviderInterface`, so the seam already planned in Module 1.1 gets filled with zero Core changes.

Honest limitation I'll surface in the docs: deriving from `wp_salt()` means if the site owner rotates their WordPress salts (rare, but possible), stored secrets become undecryptable and must be re-entered. That's an acceptable, well-documented tradeoff versus storing a separate key in the DB (which would defeat the purpose). The key-version scheme mitigates *plugin-initiated* rotation; it can't protect against the user rotating WP salts out from under it.

### 1E. Rate limiting
Requirement: throttle expensive/abusable actions (pipeline runs, API calls, login-adjacent endpoints). Design: a `RateLimiter` using a fixed-window counter stored in transients (WordPress's native ephemeral store, backed by object cache when present). Interface-first so a Redis-backed limiter can replace it later without touching callers. Fail-open vs fail-closed is configurable per-limit — a rate limiter that errors shouldn't hard-block legitimate admin work by default, but security-critical limits can opt to fail closed.

### 1F. Audit log + security events
Requirement: durably record who did what security-relevant thing (settings changed, secret accessed, permission denied, rate limit tripped). Design: `AuditLogger` writing structured entries, each carrying actor, action, target, IP, and the Core correlation ID. It emits `SecurityEvent`s through the Core `EventDispatcher` so other modules (and future Monitoring) can react. Storage: for this module I'll use a dedicated wp_option-backed rotating store consistent with Core's logger, and explicitly note that Module 3 (Storage) will migrate audit records to a proper indexed table — the `AuditLogRepositoryInterface` seam is defined now so that migration is a binding swap, not a rewrite.

### 1G. Validation & escaping (input validation, output escaping, SQL safety)
Requirement: centralize the "never trust input" primitives. Design: an `InputValidator` (typed getters over request superglobals with sanitization), reusing Core's philosophy. Output escaping stays as WordPress `esc_*` functions used at point of output (centralizing escaping is an anti-pattern — it must happen at the output site with the right context), so the module provides guidance + a few helpers rather than a wrapper. SQL safety: a thin `SafeQuery` helper enforcing `$wpdb->prepare()` usage patterns; the real payoff comes in Module 3 where actual queries live, so this is foundational only.

### 1H. HTTP egress safety (request validation, SSRF protection)
This matters because the Sources module (5) will fetch arbitrary user-supplied URLs (RSS feeds, custom sites) — a classic SSRF vector (attacker points a "feed URL" at `http://169.254.169.254/` cloud metadata or internal services). Requirement: a `SafeHttpClient` / URL guard that, before any outbound fetch, resolves the host and rejects private/loopback/link-local IP ranges and non-http(s) schemes. Design: `UrlGuard` doing scheme + DNS-resolution + IP-range checks, and an `OutboundHttpValidator` wrapping `wp_safe_remote_*`. This is built now so Sources consumes it through an interface from day one.

### 1I. Webhook signature verification (future-ready)
No webhooks exist yet, but Social/publishing integrations (Module 12) may receive them. Requirement is "future-ready," so: a `WebhookSignatureVerifier` interface + an HMAC-SHA256 implementation with constant-time comparison (`hash_equals`). Complete and tested, just not yet wired to any endpoint.

### 1J. Admin surface (security config page + diagnostics + health checks)
Requirement: a settings page (built on Core's `AbstractSettingsPage`) for security options (rate-limit toggles, fail-open/closed, key-rotation trigger), and a diagnostics/health-check panel (generalizing the old plugin's self-test) that verifies: libsodium available, WP salts defined and non-default, secrets decryptable, audit log writable, capabilities mapped. `SecurityHealthCheck` produces pass/warn/fail results consumable by both the admin page and (later) Monitoring.

## PART 2 — MODULE DESIGN

### Folder structure (all new, under `src/Security/`)
```
src/Security/
├── SecurityServiceProvider.php         # the one provider; registers + boots everything
├── Contracts/
│   ├── CapabilityGateInterface.php
│   ├── NonceManagerInterface.php
│   ├── RequestValidatorInterface.php
│   ├── RateLimiterInterface.php
│   ├── AuditLoggerInterface.php
│   ├── AuditLogRepositoryInterface.php  # storage seam for Module 3
│   ├── EncryptorInterface.php
│   ├── UrlGuardInterface.php
│   └── WebhookSignatureVerifierInterface.php
├── Authorization/
│   ├── CapabilityGate.php
│   ├── Capabilities.php                 # the custom-capability constants + role map
│   └── CapabilityInstaller.php          # adds/removes caps on activate/uninstall
├── Request/
│   ├── NonceManager.php
│   ├── RequestValidator.php             # nonce + capability + rate-limit in one guard
│   └── InputValidator.php
├── Rest/
│   └── RestSecurityMiddleware.php
├── Secrets/
│   ├── SodiumEncryptor.php              # libsodium secretbox, key from wp_salt
│   ├── CredentialVault.php              # implements Core SecretsProviderInterface
│   └── KeyProvider.php                  # derives + versions keys, supports rotation
├── RateLimit/
│   └── TransientRateLimiter.php
├── Audit/
│   ├── AuditLogger.php
│   └── OptionBackedAuditRepository.php  # Module 3 will supply a table-backed one
├── Events/
│   ├── SecurityEvent.php                # base, extends Core AbstractEvent
│   ├── PermissionDeniedEvent.php
│   ├── RateLimitExceededEvent.php
│   ├── SecretAccessedEvent.php
│   └── SuspiciousRequestEvent.php
├── Http/
│   ├── UrlGuard.php                     # SSRF checks
│   └── OutboundHttpValidator.php
├── Webhook/
│   └── HmacWebhookSignatureVerifier.php
├── Health/
│   ├── SecurityHealthCheck.php
│   └── HealthCheckResult.php
└── Admin/
    └── SecuritySettingsPage.php         # extends Core AbstractSettingsPage
```

### How it integrates with the frozen Core (no rewrites)
- **DI container**: `SecurityServiceProvider::register()` binds every interface above to its concrete. Interfaces so future modules depend on abstractions (your explicit requirement).
- **Config**: security defaults added via the `ai_news_automator_config_defaults` pattern — actually, Core's config-defaults.php is a static file; per the freeze I won't edit Core's file. Instead `SecurityServiceProvider` merges its own defaults into the `ConfigRepositoryInterface` at register time through a documented `set()`-if-absent, OR reads security config from its own `AbstractSettingsPage` option. Decision: security *toggles* live in the settings page (user-facing); security *internal constants* (e.g. rate-limit windows) ship as a Security-owned defaults array read directly by Security classes. No Core file touched.
- **Logger**: every Security class that logs takes `LoggerInterface` via constructor.
- **Events**: `SecurityEvent` extends Core's `AbstractEvent`, carrying `EventMetadata`; emitted via Core's `EventDispatcherInterface`.
- **Lifecycle**: `SecurityServiceProvider implements ActivatableInterface` — `activate()` installs capabilities, `uninstall()` removes them and wipes secrets.
- **ActivatableInterface** ordering: Security goes second in the manifest (after Core), so its caps exist before any later module needs them.

### The "no business logic bypasses Security" guarantee
Enforced by convention + interface design, not magic: later modules receive `CapabilityGateInterface`, `RequestValidatorInterface`, etc. via constructor injection and have no other sanctioned way to check auth. The audit log records permission checks so bypasses are detectable. I'll document this as a hard rule in the module README and demonstrate the pattern in the Security settings page itself (which uses its own gate).

### Testing plan
- **Unit (no WordPress)**: `SodiumEncryptor` round-trip + tamper detection, `KeyProvider` versioning, `HmacWebhookSignatureVerifier` (valid/invalid/constant-time), `UrlGuard` (blocks private/loopback/link-local/non-http), `CapabilityGate` logic, `TransientRateLimiter` window logic (with stubbed transients), `InputValidator`.
- **Integration where practical**: these need WordPress; I'll write them against a documented `wp-env`/`WP_UnitTestCase` setup and note they require that environment to run (I can't execute them here regardless).

### Crypto honesty note (will be in docs)
libsodium in PHP 8.2 core gives authenticated encryption properly. The genuine limitations: (1) key derived from `wp_salt()` — WP salt rotation orphans secrets (documented, mitigated by re-entry); (2) this protects against DB-at-rest exposure, NOT against an attacker with full filesystem/`wp-config.php` read access (nothing plugin-level can, since the app must be able to decrypt to use the keys); (3) key-version scheme supports plugin-initiated rotation but rotation is a manual admin-triggered action, not automatic. I'd rather state these plainly than imply the vault is stronger than it is.

## PART 3 — OPEN DECISIONS FOR YOUR SIGN-OFF (optional)
1. **Custom capabilities vs. mapping to existing caps** — I'm proposing custom `ana_*` capabilities. Confirm you want that granularity, or prefer mapping everything to `manage_options` for simplicity.
2. **Fail-open default for rate limiter** — I propose fail-open by default (a broken limiter shouldn't block admin work) with per-limit opt-in to fail-closed. Confirm.
3. **Audit storage now vs. wait for Module 3** — I propose an option-backed audit store now with a repository interface, migrating to a real table in Module 3. Confirm you don't want me to build the table now (it'd slightly pre-empt Module 3's Storage design).

I can proceed with my proposed defaults on all three if you'd rather not micro-decide — just say "proceed" and I'll implement as designed.

---

# DESIGN IMPROVEMENTS (approved additions)

## 1. Policy Engine
New `Authorization/PolicyEngine.php` + `Contracts/PolicyInterface.php` + `PolicyDecision` value object. Flow: a permission check names an *ability* (e.g. `content.approve`); the engine runs every registered `PolicyInterface` that handles that ability; each returns Allow/Deny/Abstain with a reason; the engine resolves (explicit Deny wins, else any Allow, else default-deny), audits the decision, and emits an event. `CapabilityGate` becomes one policy among several (the default capability-backed policy). Future modules register policies via a tagged container binding (`security.policies`) — the tagging added in 1.1 pays off here.

## 2. Threat Detection
New `Threat/ThreatDetector.php` consuming security events, keeping short-window counters (transient-backed) per (type, actor/IP). Thresholds configurable. On breach emits `ThreatDetectedEvent`. Detects: repeated nonce failures, repeated permission denials, repeated webhook failures, excessive rate-limit hits, suspicious API usage, unusual admin activity. Purely observational now; Monitoring consumes later.

## 3. Audit entry shape (expanded)
`AuditEntry` value object: actor (id + login), action, target, correlationId, ip, userAgent, module, severity, timestamp, result (Success/Failure enum). `AuditLogRepositoryInterface` unchanged as the storage seam. Option-backed now, table-backed in Module 3 — callers never change.

## 4. Encryption payload metadata
`EncryptedPayload` value object serialized as JSON envelope: `{v: encVersion, alg: algorithmId, kid: keyId, n: nonce, ct: ciphertext}`. `SodiumEncryptor` writes/reads this envelope so a future algorithm swap is detectable per-payload and mixed-version data decrypts correctly.

## 5. Secret metadata
`SecretRecord` wraps the encrypted value plus: expiresAt, lastValidatedAt, lastUsedAt, provider, createdAt. `CredentialVault` stores/returns records; convenience `get()` still returns the bare string (implements Core `SecretsProviderInterface`), with `getRecord()` for metadata. Dashboards (later) read metadata.

## 6. UrlGuard (expanded)
Blocks: localhost/loopback (127.0.0.0/8, ::1), private IPv4 (10/8, 172.16/12, 192.168/16), private/unique-local IPv6 (fc00::/7), link-local (169.254/16, fe80::/10), cloud metadata (169.254.169.254, fd00:ec2::254). DNS-rebinding: resolves host and validates the *resolved IP* (not just hostname), and exposes the resolved IP so callers can pin it for the actual request. Admin allowlist supported (trusted hosts bypass, for legitimate internal feeds).

## 7. Webhooks (multi-algorithm)
`WebhookSignatureVerifierInterface` provider-agnostic. `HmacWebhookSignatureVerifier` supports sha256 + sha512 (constant-time `hash_equals`). `Ed25519WebhookSignatureVerifier` interface-ready, implemented via libsodium (`sodium_crypto_sign_verify_detached`) since it's cheap to include correctly now.

## 8. HealthCheckResult (expanded)
Fields: status (enum: Ok/Warning/Critical), severity, recommendation, autoFixAvailable (bool), docsUrl. Each `SecurityHealthCheck` sub-check returns this. Monitoring-consumable.

## 9. Security metrics
`Metrics/SecurityMetrics.php` increments transient/option counters: denied requests, nonce failures, successful validations, decrypt operations, rate-limit hits, webhook failures. `SecurityMetricsInterface` so Analytics reads them later.

## 10. Enterprise extension points (interfaces only, no impl)
Define but don't implement: `Contracts/TwoFactorProviderInterface`, `Contracts/IpAccessPolicyInterface` (allow/block lists — actually implemented minimally as a policy since it's cheap and useful), `Contracts/AccountLockoutInterface`, `Contracts/SessionMonitorInterface`, `Contracts/SecurityNotifierInterface`. These are open seams; wiring them is future work.

## 11. Testing (expanded)
Add: validator fuzz tests (random/malformed input never throws unexpectedly), malformed encrypted-payload tests, tamper tests (bit-flip ciphertext → auth failure), SSRF bypass tests (decimal IPs, IPv6-mapped IPv4, DNS-rebind shapes), replay tests (nonce/webhook reuse), permission regression tests (policy resolution matrix).

## 12. Threat Model (in README)
Explicit section: assets (API credentials, content pipeline integrity, admin access), attack vectors (DB leak, SSRF, CSRF, credential theft, replay, privilege escalation), trust boundaries (browser↔WP, WP↔external APIs, WP↔user-supplied URLs), assumptions (wp-config integrity, WP core patched), limitations (can't defend against filesystem read, salt rotation orphans secrets, not a WAF).

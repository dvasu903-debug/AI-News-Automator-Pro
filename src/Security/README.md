# Module 2: Security

The authorization, integrity, secrets, and threat-detection layer. Every other module consumes Security through the interfaces in `Contracts/` — no business logic performs its own authorization, encryption, or outbound-URL validation.

> Part of the **AI Publishing Engine** (the platform powering the *AI News Automator Pro* plugin). This is engine-level infrastructure: it is domain-agnostic and shared by every publishing vertical, not specific to news. Code identifiers use the retained `AINewsAutomator\` namespace and `ana_` prefixes for backward compatibility.

## Components

**Authorization (policy engine)** — `CapabilityGate` is the single entry point (`allows()` / `authorize()`). It runs the `PolicyEngine`, which aggregates every registered `PolicyInterface` (tagged `security.policies` in the container). Flow: **Permission → Policy → Decision → Audit → Event**. Resolution is deny-wins, then allow, then default-deny. The `DefaultCapabilityPolicy` maps abilities (e.g. `content.approve`) to fine-grained custom capabilities (`ana_approve_content`, `ana_manage_security`, …) so an editorial team can approve content without touching settings or security. Future modules register their own policies without modifying Security.

**Request integrity** — `NonceManager` (plugin-namespaced WordPress nonces), `RequestValidator` (nonce + capability + optional rate-limit in one fail-closed guard), `InputValidator` (typed, sanitizing request accessors). A failed nonce emits a `SuspiciousRequestEvent`.

**REST middleware** — `RestSecurityMiddleware` produces `permission_callback`s that enforce ability + rate-limit on REST routes. Opt-in; does not modify Core's frozen `AbstractRestController`.

**Secrets** — `CredentialVault` (implements Core's `SecretsProviderInterface`) encrypts API keys at rest via `SodiumEncryptor` (libsodium secretbox). `EncryptedPayload` carries version/algorithm/key-id metadata for future crypto upgrades. `KeyProvider` derives versioned keys from WordPress salts (never stored in the DB) and supports rotation. `SecretRecord` tracks provider, expiry, last-validated, last-used metadata.

**Rate limiting** — `TransientRateLimiter`, fixed-window, fail-open by default (a broken limiter shouldn't block admin work), with fail-closed available for critical paths.

**Audit** — `AuditLogger` records entries with actor, action, target, correlation ID, IP, user agent, module, severity, timestamp, and result. Stored via `AuditLogRepositoryInterface` (option-backed now; Module 3 swaps in a table with zero caller changes). Every entry also emits into the Core log and event stream.

**Threat detection** — `ThreatDetector` subscribes to security events, counts per-subject occurrences in a sliding window, and emits `ThreatDetectedEvent` on threshold breach (repeated permission denials, nonce failures, rate-limit hits, webhook failures). Detection only; Monitoring handles response later.

**HTTP egress / SSRF** — `UrlGuard` blocks loopback, private IPv4/IPv6, link-local, unique-local, and cloud-metadata destinations; resolves hostnames and validates the resolved IP (DNS-rebinding defense), returning it for connection pinning. Admin allowlist supported. `OutboundHttpValidator` wraps `wp_safe_remote_*` behind the guard so Sources (Module 5) can't accidentally fetch internal URLs.

**Webhooks (future-ready)** — `WebhookSignatureVerifierInterface` with HMAC (sha256/sha512, constant-time) and Ed25519 (libsodium) implementations. No endpoint consumes them yet.

**Health & metrics** — `SecurityHealthCheck` returns rich results (status, severity, recommendation, auto-fix availability, docs link). `SecurityMetrics` counts denied requests, nonce failures, successful validations, decrypt operations, rate-limit hits, webhook failures — read by Analytics later.

**Admin** — `SecuritySettingsPage` (extends Core's `AbstractSettingsPage`) provides the config form plus a live diagnostics/metrics/audit panel. Requires the `ana_manage_security` capability — the module gates its own admin surface.

**Enterprise extension points** — interfaces only, not implemented: 2FA, IP allow/block, account lockout, session monitoring, security notifications. Open seams for future work.

## The "no bypass" rule

Modules receive `CapabilityGateInterface`, `RequestValidatorInterface`, `EncryptorInterface`, `UrlGuardInterface`, etc. via constructor injection. There is no sanctioned path to check authorization, encrypt a secret, or fetch a user-supplied URL except through these. The gate audits every decision, so a bypass attempt (calling `current_user_can` directly) is both a code-review failure and, where it matters, detectable by the absence of a corresponding audit entry.

---

## THREAT MODEL

### Assets protected
- **API credentials** (Anthropic, NewsAPI, Unsplash, etc.) — the highest-value asset; their theft means financial loss and impersonation.
- **Content pipeline integrity** — preventing unauthorized triggering, approval, or publishing of content.
- **Admin access & configuration** — settings, security config, capability assignments.
- **Audit trail integrity** — the record of who did what.

### Attack vectors addressed
- **Database-at-rest exposure** (leaked backup, SQL injection elsewhere, compromised DB user): API keys are encrypted with a key derived from `wp-config.php` salts, so DB contents alone yield only ciphertext.
- **CSRF**: state-changing actions require plugin-namespaced nonces via `RequestValidator`.
- **Privilege escalation / broken access control**: all authorization flows through the policy engine with default-deny.
- **SSRF** (via user-supplied feed/source URLs): `UrlGuard` blocks internal/metadata destinations and validates resolved IPs.
- **Replay** (nonce reuse, webhook signature replay): nonces are single-window and action-scoped; webhook signatures are payload-bound and constant-time compared.
- **Brute-force / abuse**: rate limiting + threat detection with escalation events.
- **Timing side channels**: `hash_equals` / libsodium constant-time comparisons for all signature/secret checks.

### Trust boundaries
1. **Browser ↔ WordPress**: untrusted input; every request validated (nonce, capability, sanitization).
2. **WordPress ↔ external APIs**: outbound; credentials injected server-side, never exposed to the browser.
3. **WordPress ↔ user-supplied URLs**: strongly untrusted; `UrlGuard` mediates every fetch.
4. **Plugin ↔ WordPress core/DB**: trusted (see assumptions).

### Assumptions
- `wp-config.php` and the server filesystem are not readable by an attacker. If they are, the game is already lost at a level no plugin can address (the app must be able to decrypt to function, so the key is reachable to code running as the app).
- WordPress core and PHP are reasonably patched; libsodium (PHP 8.2+ core) is available.
- The site's WordPress salts are unique and secret (the health check flags placeholder/missing salts).
- The hosting environment's object cache / options table is not attacker-writable.

### Limitations (explicit)
- **Not a WAF or IDS**: this protects the plugin's own surfaces; it does not inspect all site traffic or defend other plugins/themes.
- **Filesystem read = full compromise**: encryption protects against DB-only exposure, not an attacker who can read `wp-config.php`.
- **WordPress salt rotation orphans secrets**: because keys derive from salts, rotating WP salts renders stored secrets undecryptable — they must be re-entered. This is the deliberate cost of not storing a key in the DB. The health check and docs call this out; the key-version scheme handles *plugin-initiated* rotation, not user salt rotation.
- **Rate limiting is fixed-window**: susceptible to a burst at the window boundary. Acceptable for abuse-throttling; the interface allows a sliding-window backend later.
- **Threat detection is heuristic**: threshold-based counters, not ML. It surfaces signals for a human/Monitoring to act on; it is not an autonomous blocking system in Module 2.
- **XFF spoofing**: client IP uses `REMOTE_ADDR` by default; sites behind a trusted proxy must opt into a forwarded header explicitly, because trusting it by default would let attackers forge their apparent IP.

## Testing

Unit tests (offline): encryption round-trip + tamper + malformed-payload + wrong-key + unsupported-algorithm; SSRF blocking matrix (loopback, private v4/v6, link-local, unique-local, metadata, non-http schemes, embedded credentials, decimal-IP); webhook valid/tampered/replayed/wrong-secret/constant-time; policy-engine permission regression matrix (allow/deny/abstain/default-deny/wildcard/veto-ordering); rate-limiter window behavior; input-validator fuzz (binary, huge, unicode, SQL-ish, HTML, arrays, null) asserting no getter ever throws; key derivation determinism + versioning.

Integration tests (require WordPress; documented, not runnable in this build environment): capability installation on real roles, nonce lifecycle against real `wp_verify_nonce`, REST permission callbacks against real requests, DNS-resolution SSRF paths.

**I could not execute any of these here** (no network for `composer install`, no PHP runtime for the suite). Validation performed: brace/paren balance, PSR-4 namespace-to-path and one-type-per-file compliance, import/reference resolution, and dependency-graph tracing for the container bindings. Run `composer test` locally before relying on this module.

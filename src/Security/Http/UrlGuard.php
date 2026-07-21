<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Http;

use AINewsAutomator\Security\Contracts\UrlGuardInterface;

/**
 * SSRF guard for outbound requests to user-supplied URLs (RSS feeds, custom
 * source sites in Module 5). Rejects anything that could reach internal
 * infrastructure:
 *   - non-http(s) schemes (file://, gopher://, dict://, ...)
 *   - credentials in the URL (user:pass@host — often used to confuse parsers)
 *   - hosts resolving to loopback, private, link-local, or unique-local ranges
 *   - cloud metadata endpoints (169.254.169.254 and the IPv6 equivalent)
 *
 * DNS-rebinding defense: it resolves the host to an IP and validates the
 * RESOLVED IP, then returns that IP so the caller pins it for the actual
 * request — preventing a hostname that passes validation from resolving to
 * a different, internal IP at connection time.
 *
 * Administrators may allowlist specific hosts (e.g. a legitimate internal
 * feed) via the 'ai_news_automator_url_allowlist' filter; allowlisted hosts
 * bypass the IP-range checks by exact hostname match.
 */
final class UrlGuard implements UrlGuardInterface
{
    public function inspect(string $url): UrlInspectionResult
    {
        $parts = wp_parse_url($url);

        // Scheme is checked before host presence: a URL like
        // file:///etc/passwd has no host component at all, and should be
        // rejected for its scheme, not misreported as merely malformed —
        // scheme is what determines whether "no host" is even meaningful
        // for this URL in the first place.
        if (!is_array($parts) || empty($parts['scheme'])) {
            return UrlInspectionResult::blocked('malformed', 'URL is malformed or missing scheme/host.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return UrlInspectionResult::blocked('scheme', sprintf('Scheme "%s" is not permitted; only http/https.', $scheme));
        }

        if (empty($parts['host'])) {
            return UrlInspectionResult::blocked('malformed', 'URL is malformed or missing scheme/host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return UrlInspectionResult::blocked('credentials', 'URLs embedding credentials are not permitted.');
        }

        // parse_url()/wp_parse_url() return an IPv6 literal host WITH its
        // enclosing brackets (e.g. "[::1]") — neither filter_var(...,
        // FILTER_VALIDATE_IP) nor inet_pton() accept that bracketed form,
        // so it must be stripped before any IP-literal handling below.
        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // Admin allowlist: exact host match bypasses IP checks.
        if (in_array($host, $this->allowlist(), true)) {
            $resolved = $this->resolveHost($host);
            return UrlInspectionResult::allowed($resolved ?? $host);
        }

        // If the host is a literal IP, validate it directly; else resolve.
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : $this->resolveHost($host);

        if ($ip === null) {
            return UrlInspectionResult::blocked('unresolvable', sprintf('Host "%s" could not be resolved.', $host));
        }

        if (!$this->isPublicIp($ip)) {
            return UrlInspectionResult::blocked('private_ip', sprintf('Host resolves to a non-public address (%s).', $ip), $ip);
        }

        return UrlInspectionResult::allowed($ip);
    }

    public function isAllowed(string $url): bool
    {
        return $this->inspect($url)->allowed;
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        $list = apply_filters('ai_news_automator_url_allowlist', []);

        if (!is_array($list)) {
            return [];
        }

        return array_values(array_map(
            static fn ($h): string => strtolower((string) $h),
            $list
        ));
    }

    private function resolveHost(string $host): ?string
    {
        // Prefer an IPv4 A record; fall back to the first record of any type.
        $records = @dns_get_record($host, DNS_A);

        if (is_array($records) && isset($records[0]['ip'])) {
            return (string) $records[0]['ip'];
        }

        $ipv6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6) && isset($ipv6[0]['ipv6'])) {
            return (string) $ipv6[0]['ipv6'];
        }

        // Last resort: gethostbyname (IPv4 only; returns input on failure).
        $resolved = gethostbyname($host);

        return ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) !== false)
            ? $resolved
            : null;
    }

    /**
     * True only if the IP is a routable public address. Uses PHP's filter
     * with the reserved+private range flags, then adds explicit metadata and
     * IPv6 unique-local checks the filter doesn't fully cover.
     */
    private function isPublicIp(string $ip): bool
    {
        // Cloud metadata endpoints — block explicitly regardless of filter behavior.
        $metadataHosts = ['169.254.169.254', 'fd00:ec2::254'];
        if (in_array($ip, $metadataHosts, true)) {
            return false;
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            return false;
        }

        // IPv6 unique-local (fc00::/7) — some PHP versions don't flag these
        // via NO_PRIV_RANGE, so check the leading bits explicitly.
        if (str_contains($ip, ':')) {
            $binary = @inet_pton($ip);
            if ($binary !== false && strlen($binary) === 16) {
                $firstByte = ord($binary[0]);
                // fc00::/7 => top 7 bits are 1111110
                if (($firstByte & 0xFE) === 0xFC) {
                    return false;
                }
                // fe80::/10 link-local
                if ($firstByte === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) {
                    return false;
                }
            }
        }

        return true;
    }
}

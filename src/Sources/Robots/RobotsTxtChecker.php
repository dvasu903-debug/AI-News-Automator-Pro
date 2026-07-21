<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Robots;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Sources\Contracts\RobotsTxtCheckerInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;

/**
 * Fetches, parses, and transient-caches robots.txt per domain (changes
 * rarely — same caching posture as AI's response cache, ADR-0008's
 * reasoning applied here). Fetches through Security's OutboundHttpValidator
 * — the same SSRF-guarded HTTP boundary every other outbound call in the
 * plugin uses.
 *
 * Fetch-failure policy (a deliberate, conservative safety choice, not the
 * literal RFC 9309 nuance): a 404 means robots.txt genuinely doesn't
 * exist -> fully permissive. Any OTHER failure (timeout, 5xx, connection
 * error) -> deny all paths except the robots.txt fetch itself. Being
 * unable to confirm permission is treated as "assume no permission,"
 * which is the more defensible default for a plugin that must be a good
 * citizen crawler, even though it's stricter than RFC 9309 technically
 * requires for 5xx responses.
 */
final class RobotsTxtChecker implements RobotsTxtCheckerInterface
{
    private const CACHE_TTL_SECONDS = 86400; // 24 hours — robots.txt changes rarely.
    private const CACHE_PREFIX = 'ana_robots_';

    public function __construct(
        private readonly OutboundHttpValidator $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAllowed(string $url, string $userAgent): bool
    {
        $parts = wp_parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $rules = $this->rulesFor((string) ($parts['scheme'] ?? 'https'), (string) $parts['host'], $userAgent);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return $rules->isPathAllowed($path);
    }

    public function discoveredSitemaps(string $domain): array
    {
        return $this->rulesFor('https', $domain, '*')->sitemaps;
    }

    private function rulesFor(string $scheme, string $host, string $userAgent): RobotsRules
    {
        $cacheKey = self::CACHE_PREFIX . md5($host);
        $cached = get_transient($cacheKey);

        if ($cached instanceof RobotsRules) {
            return $cached;
        }

        $rules = $this->fetchAndParse($scheme, $host, $userAgent);
        set_transient($cacheKey, $rules, self::CACHE_TTL_SECONDS);

        return $rules;
    }

    private function fetchAndParse(string $scheme, string $host, string $userAgent): RobotsRules
    {
        $url = $scheme . '://' . $host . '/robots.txt';
        $response = $this->http->get($url, ['timeout' => 10]);

        if (is_wp_error($response)) {
            $this->logger->warning('robots.txt fetch failed for {host}: {error} — defaulting to deny-all for safety.', [
                'host'  => $host,
                'error' => $response->get_error_message(),
            ]);
            return RobotsRules::denyAll();
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status === 404) {
            return RobotsRules::permissive();
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('robots.txt returned HTTP {status} for {host} — defaulting to deny-all for safety.', [
                'status' => $status,
                'host'   => $host,
            ]);
            return RobotsRules::denyAll();
        }

        return $this->parse((string) wp_remote_retrieve_body($response), $userAgent);
    }

    /**
     * Standard robots.txt grouping: a run of consecutive "User-agent:"
     * lines shares the Disallow/Allow rules that follow, until the next
     * User-agent line (which starts a new group) or end of file.
     */
    private function parse(string $body, string $userAgent): RobotsRules
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        /** @var array<string, array{disallow: list<string>, allow: list<string>}> $groups */
        $groups = [];
        $currentAgents = [];
        $sitemaps = [];
        $inAgentBlock = false;

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);

            if ($directive === 'user-agent') {
                if (!$inAgentBlock) {
                    $currentAgents = [];
                }
                $agent = strtolower($value);
                $currentAgents[] = $agent;
                $groups[$agent] ??= ['disallow' => [], 'allow' => []];
                $inAgentBlock = true;
                continue;
            }

            $inAgentBlock = false;

            switch ($directive) {
                case 'disallow':
                    foreach ($currentAgents as $agent) {
                        $groups[$agent]['disallow'][] = $value;
                    }
                    break;
                case 'allow':
                    foreach ($currentAgents as $agent) {
                        $groups[$agent]['allow'][] = $value;
                    }
                    break;
                case 'sitemap':
                    if ($value !== '') {
                        $sitemaps[] = $value;
                    }
                    break;
            }
        }

        $agentKey = strtolower($userAgent);
        $matched = $groups[$agentKey] ?? $groups['*'] ?? null;

        if ($matched === null) {
            return new RobotsRules([], [], $sitemaps);
        }

        return new RobotsRules($matched['disallow'], $matched['allow'], $sitemaps);
    }
}

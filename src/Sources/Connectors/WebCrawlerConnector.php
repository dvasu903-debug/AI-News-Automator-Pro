<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Connectors;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Sources\Contracts\CrawlConnectorInterface;
use AINewsAutomator\Sources\Contracts\RobotsTxtCheckerInterface;
use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * Discovers candidate article links from one listing page. Scoped
 * deliberately narrow: it fetches ONE page and extracts links from it —
 * it does not itself follow those links to fetch full article content
 * (that would be many additional HTTP calls per crawl and belongs to a
 * future module once an item is deemed worth deeper processing; see the
 * approved design's explicit scope boundary — Module 5 discovers, it
 * does not decide what happens next).
 *
 * Robots.txt compliance is mandatory and unconditional: a disallowed
 * seed URL is never fetched, regardless of any other configuration.
 */
final class WebCrawlerConnector extends AbstractHttpConnector implements CrawlConnectorInterface
{
    private const DEFAULT_USER_AGENT = 'AINewsAutomatorBot/1.0 (+https://example.com/bot)';
    private const DEFAULT_MAX_LINKS = 50;

    public function __construct(
        OutboundHttpValidator $http,
        LoggerInterface $logger,
        RateLimiterInterface $rateLimiter,
        private readonly RobotsTxtCheckerInterface $robots,
    ) {
        parent::__construct($http, $logger, $rateLimiter);
    }

    public function type(): string
    {
        return 'web_crawler';
    }

    public function fetch(SourceRecord $source): FetchResult
    {
        $config = $source->config;
        $seedUrl = (string) ($config['seed_url'] ?? $config['url'] ?? '');

        if ($seedUrl === '') {
            return FetchResult::failed('Source config is missing a "seed_url".');
        }

        $userAgent = (string) ($config['user_agent'] ?? self::DEFAULT_USER_AGENT);

        if (!$this->robots->isAllowed($seedUrl, $userAgent)) {
            return FetchResult::failed(sprintf('Crawling "%s" is disallowed by robots.txt.', $seedUrl));
        }

        $html = $this->fetchUrl($seedUrl, ['User-Agent' => $userAgent]);
        $maxLinks = (int) ($config['max_links'] ?? self::DEFAULT_MAX_LINKS);
        $sameDomainOnly = (bool) ($config['same_domain_only'] ?? true);

        $items = $this->extractLinks($html, $seedUrl, $maxLinks, $sameDomainOnly);

        return FetchResult::success($items);
    }

    /**
     * @return list<NormalizedItem>
     */
    private function extractLinks(string $html, string $baseUrl, int $maxLinks, bool $sameDomainOnly): array
    {
        if (trim($html) === '') {
            return [];
        }

        $previousSetting = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // HTML from the open web is routinely malformed; loadHTML with
        // these flags tolerates that without emitting warnings for it.
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        $baseHost = (string) (wp_parse_url($baseUrl)['host'] ?? '');
        $seen = [];
        $items = [];

        foreach ($doc->getElementsByTagName('a') as $anchor) {
            if (count($items) >= $maxLinks) {
                break;
            }

            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
            if ($absoluteUrl === null || isset($seen[$absoluteUrl])) {
                continue;
            }

            if ($sameDomainOnly) {
                $linkHost = (string) (wp_parse_url($absoluteUrl)['host'] ?? '');
                if ($linkHost !== $baseHost) {
                    continue;
                }
            }

            $seen[$absoluteUrl] = true;
            $text = trim((string) preg_replace('/\s+/', ' ', $anchor->textContent));

            $items[] = new NormalizedItem(
                url: $absoluteUrl,
                title: $text !== '' ? $text : null,
            );
        }

        return $items;
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $base = wp_parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        // Relative path — resolve against the base URL's directory.
        $basePath = $base['path'] ?? '/';
        $lastSlash = strrpos($basePath, '/');
        $baseDir = $lastSlash !== false ? substr($basePath, 0, $lastSlash + 1) : '/';

        return $scheme . '://' . $host . $baseDir . $href;
    }
}

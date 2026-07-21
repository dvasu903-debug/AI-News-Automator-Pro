<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Connectors;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Sources\Contracts\RobotsTxtCheckerInterface;
use AINewsAutomator\Sources\Contracts\SitemapConnectorInterface;
use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Sources\Retry\SourceFetchErrorType;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * Parses XML sitemaps, including one level of sitemap-index nesting
 * (real-world sitemap indexes are almost always exactly one level deep;
 * deeper recursion is deliberately not supported, to avoid an unbounded
 * fetch chain). Bounds total URLs returned per fetch — a large sitemap
 * should not overwhelm the queue in a single sync pass.
 */
final class SitemapConnector extends AbstractHttpConnector implements SitemapConnectorInterface
{
    private const DEFAULT_USER_AGENT = 'AINewsAutomatorBot/1.0 (+https://example.com/bot)';
    private const DEFAULT_MAX_URLS = 500;
    private const DEFAULT_MAX_CHILD_SITEMAPS = 10;

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
        return 'sitemap';
    }

    public function fetch(SourceRecord $source): FetchResult
    {
        $config = $source->config;
        $url = (string) ($config['url'] ?? '');

        if ($url === '') {
            return FetchResult::failed('Source config is missing a "url".');
        }

        $userAgent = (string) ($config['user_agent'] ?? self::DEFAULT_USER_AGENT);

        if (!$this->robots->isAllowed($url, $userAgent)) {
            return FetchResult::failed(sprintf('Fetching sitemap "%s" is disallowed by robots.txt.', $url));
        }

        $maxUrls = (int) ($config['max_urls'] ?? self::DEFAULT_MAX_URLS);
        $maxChildSitemaps = (int) ($config['max_child_sitemaps'] ?? self::DEFAULT_MAX_CHILD_SITEMAPS);

        $xml = $this->fetchUrl($url, ['User-Agent' => $userAgent]);
        $doc = $this->parseXml($xml);

        $items = $doc->getName() === 'sitemapindex'
            ? $this->fetchChildSitemaps($doc, $userAgent, $maxUrls, $maxChildSitemaps)
            : $this->parseUrlset($doc);

        $items = $this->applyWatermark($items, $source->lastFetchedAt);

        return FetchResult::success(array_slice($items, 0, $maxUrls));
    }

    private function parseXml(string $xml): \SimpleXMLElement
    {
        $previousSetting = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if ($doc === false) {
            $detail = $errors !== [] ? $errors[0]->message : 'unknown parse error';
            throw new SourceFetchException('Failed to parse sitemap XML: ' . trim($detail), SourceFetchErrorType::MalformedContent);
        }

        return $doc;
    }

    /**
     * @return list<NormalizedItem>
     */
    private function fetchChildSitemaps(\SimpleXMLElement $index, string $userAgent, int $maxUrls, int $maxChildSitemaps): array
    {
        $items = [];
        $count = 0;

        foreach ($index->sitemap as $entry) {
            if ($count >= $maxChildSitemaps || count($items) >= $maxUrls) {
                break;
            }

            $childUrl = trim((string) $entry->loc);
            if ($childUrl === '' || !$this->robots->isAllowed($childUrl, $userAgent)) {
                continue;
            }

            try {
                $childXml = $this->fetchUrl($childUrl, ['User-Agent' => $userAgent]);
                $childDoc = $this->parseXml($childXml);
                array_push($items, ...$this->parseUrlset($childDoc));
            } catch (SourceFetchException $e) {
                $this->logger->warning('Skipping unreadable child sitemap {url}: {reason}', [
                    'url'    => $childUrl,
                    'reason' => $e->getMessage(),
                ]);
            }

            $count++;
        }

        return $items;
    }

    /**
     * @return list<NormalizedItem>
     */
    private function parseUrlset(\SimpleXMLElement $doc): array
    {
        $items = [];

        foreach ($doc->url as $urlEl) {
            $loc = trim((string) $urlEl->loc);
            if ($loc === '') {
                continue;
            }

            $lastmodRaw = trim((string) $urlEl->lastmod);
            $publishedAt = null;

            if ($lastmodRaw !== '') {
                try {
                    $publishedAt = new \DateTimeImmutable($lastmodRaw);
                } catch (\Exception) {
                    $publishedAt = null;
                }
            }

            $items[] = new NormalizedItem(url: $loc, publishedAt: $publishedAt);
        }

        return $items;
    }

    /**
     * @param list<NormalizedItem> $items
     * @return list<NormalizedItem>
     */
    private function applyWatermark(array $items, ?\DateTimeImmutable $since): array
    {
        if ($since === null) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (NormalizedItem $item): bool => $item->publishedAt === null || $item->publishedAt > $since
        ));
    }
}

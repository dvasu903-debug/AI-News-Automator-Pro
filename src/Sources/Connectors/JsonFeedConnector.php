<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Connectors;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Sources\Contracts\FeedConnectorInterface;
use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Sources\Retry\SourceFetchErrorType;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * ONE class for every JSON-API-based source (NewsAPI.org, GNews,
 * Mediastack, ...), configured per-source via field mapping in
 * SourceRecord::$config — NOT a same-wire-format consolidation like AI's
 * OpenAiCompatibleProvider (no shared standard exists across news APIs,
 * unlike OpenAI-compatibility, which is a real, vendor-confirmed
 * standard — see the approved design's naming note on this distinction).
 *
 * Expected config keys: url (required), items_path (dot-notation path to
 * the articles array, e.g. "articles" or "response.results"),
 * title_field/url_field/published_field/summary_field/author_field/
 * guid_field (each dot-notation, default to sensible common names), and
 * optionally api_key_header/api_key_secret_key/api_key_format for
 * authenticated APIs (the key itself resolved via Security's
 * SecretsProviderInterface, never stored in the config JSON directly).
 */
final class JsonFeedConnector extends AbstractHttpConnector implements FeedConnectorInterface
{
    public function __construct(
        OutboundHttpValidator $http,
        LoggerInterface $logger,
        RateLimiterInterface $rateLimiter,
        private readonly SecretsProviderInterface $secrets,
    ) {
        parent::__construct($http, $logger, $rateLimiter);
    }

    public function type(): string
    {
        return 'json_feed';
    }

    public function fetch(SourceRecord $source): FetchResult
    {
        $config = $source->config;
        $url = (string) ($config['url'] ?? '');

        if ($url === '') {
            return FetchResult::failed('Source config is missing a "url".');
        }

        $body = $this->fetchUrl($url, $this->buildHeaders($config));
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new SourceFetchException('Failed to parse JSON response.', SourceFetchErrorType::MalformedContent);
        }

        $itemsPath = (string) ($config['items_path'] ?? '');
        $rawItems = $itemsPath !== '' ? $this->extractPath($decoded, $itemsPath) : $decoded;

        if (!is_array($rawItems)) {
            throw new SourceFetchException(sprintf('items_path "%s" did not resolve to an array.', $itemsPath), SourceFetchErrorType::MalformedContent);
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $item = $this->mapItem($rawItem, $config);

            if ($item === null) {
                continue;
            }

            if ($source->lastFetchedAt !== null && $item->publishedAt !== null && $item->publishedAt <= $source->lastFetchedAt) {
                continue;
            }

            $items[] = $item;
        }

        return FetchResult::success($items);
    }

    /**
     * @param array<string, mixed> $rawItem
     * @param array<string, mixed> $config
     */
    private function mapItem(array $rawItem, array $config): ?NormalizedItem
    {
        $url = (string) $this->extractPath($rawItem, (string) ($config['url_field'] ?? 'url'));

        if ($url === '') {
            return null;
        }

        $publishedRaw = (string) $this->extractPath($rawItem, (string) ($config['published_field'] ?? 'publishedAt'));

        return new NormalizedItem(
            url: $url,
            title: $this->nullableString($this->extractPath($rawItem, (string) ($config['title_field'] ?? 'title'))),
            publishedAt: $this->parseDate($publishedRaw),
            summary: $this->nullableString($this->extractPath($rawItem, (string) ($config['summary_field'] ?? 'description'))),
            author: $this->nullableString($this->extractPath($rawItem, (string) ($config['author_field'] ?? 'author'))),
            guid: $this->nullableString($this->extractPath($rawItem, (string) ($config['guid_field'] ?? ''))),
        );
    }

    /**
     * Resolves a dot-notation path against a decoded JSON array, e.g.
     * "response.articles" -> $data['response']['articles'].
     */
    private function extractPath(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function buildHeaders(array $config): array
    {
        $headerName = (string) ($config['api_key_header'] ?? '');
        $secretKey = (string) ($config['api_key_secret_key'] ?? '');

        if ($headerName === '' || $secretKey === '') {
            return [];
        }

        $apiKey = $this->secrets->get($secretKey);

        if ($apiKey === null) {
            return [];
        }

        $format = (string) ($config['api_key_format'] ?? '%s');

        return [$headerName => sprintf($format, $apiKey)];
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }
}

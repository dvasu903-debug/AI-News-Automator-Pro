<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Connectors;

use AINewsAutomator\Sources\Contracts\FeedConnectorInterface;
use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Sources\Retry\SourceFetchErrorType;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * Handles both RSS 2.0 (<rss><channel><item>) and Atom (<feed><entry>)
 * feeds. Deliberately does NOT use WordPress core's fetch_feed()/SimplePie
 * — that machinery makes its own HTTP request outside Security's
 * OutboundHttpValidator, which would silently bypass the SSRF guard every
 * other outbound call in the plugin goes through. Instead: fetch the raw
 * XML via AbstractHttpConnector's guarded fetchUrl(), then parse the
 * already-fetched string locally with PHP's built-in SimpleXML — no
 * network call happens during parsing.
 */
final class RssConnector extends AbstractHttpConnector implements FeedConnectorInterface
{
    public function type(): string
    {
        return 'rss';
    }

    public function fetch(SourceRecord $source): FetchResult
    {
        $url = (string) ($source->config['url'] ?? '');

        if ($url === '') {
            return FetchResult::failed('Source config is missing a "url".');
        }

        $xml = $this->fetchUrl($url);
        $items = $this->parse($xml, $source->lastFetchedAt);

        return FetchResult::success($items);
    }

    /**
     * @return list<NormalizedItem>
     *
     * @throws SourceFetchException
     */
    private function parse(string $xml, ?\DateTimeImmutable $since): array
    {
        $previousSetting = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if ($doc === false) {
            $detail = $errors !== [] ? $errors[0]->message : 'unknown parse error';
            throw new SourceFetchException('Failed to parse feed XML: ' . trim($detail), SourceFetchErrorType::MalformedContent);
        }

        if (isset($doc->channel)) {
            return $this->parseRss2($doc, $since);
        }

        if ($doc->getName() === 'feed') {
            return $this->parseAtom($doc, $since);
        }

        throw new SourceFetchException('Unrecognized feed format: neither RSS 2.0 nor Atom root element found.', SourceFetchErrorType::MalformedContent);
    }

    /**
     * @return list<NormalizedItem>
     */
    private function parseRss2(\SimpleXMLElement $doc, ?\DateTimeImmutable $since): array
    {
        $items = [];

        foreach ($doc->channel->item as $item) {
            $publishedAt = $this->parseDate((string) $item->pubDate);

            if ($since !== null && $publishedAt !== null && $publishedAt <= $since) {
                continue;
            }

            $link = trim((string) $item->link);
            if ($link === '') {
                continue;
            }

            $items[] = new NormalizedItem(
                url: $link,
                title: $this->nullableText((string) $item->title),
                publishedAt: $publishedAt,
                summary: $this->nullableText((string) $item->description),
                author: $this->nullableText((string) ($item->author ?? $item->children('dc', true)->creator ?? '')),
                guid: $this->nullableText((string) $item->guid),
            );
        }

        return $items;
    }

    /**
     * @return list<NormalizedItem>
     */
    private function parseAtom(\SimpleXMLElement $doc, ?\DateTimeImmutable $since): array
    {
        $items = [];

        foreach ($doc->entry as $entry) {
            $publishedAt = $this->parseDate((string) ($entry->published ?: $entry->updated));

            if ($since !== null && $publishedAt !== null && $publishedAt <= $since) {
                continue;
            }

            $link = '';
            foreach ($entry->link as $linkEl) {
                $attrs = $linkEl->attributes();
                if ((string) ($attrs['rel'] ?? 'alternate') === 'alternate' || $link === '') {
                    $link = (string) ($attrs['href'] ?? '');
                }
            }

            if ($link === '') {
                continue;
            }

            $items[] = new NormalizedItem(
                url: $link,
                title: $this->nullableText((string) $entry->title),
                publishedAt: $publishedAt,
                summary: $this->nullableText((string) ($entry->summary ?: $entry->content)),
                author: $this->nullableText((string) ($entry->author->name ?? '')),
                guid: $this->nullableText((string) $entry->id),
            );
        }

        return $items;
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

    private function nullableText(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}

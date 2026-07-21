<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Storage\Entities\SourceRecord;

/**
 * A connector translates one kind of external source (RSS feed, JSON
 * API, crawled website, XML sitemap) into the provider-agnostic
 * NormalizedItem shape. type() matches SourceRecord::$type — the
 * registry resolves a connector by that string, never by class.
 */
interface SourceConnectorInterface
{
    /** Matches SourceRecord::$type, e.g. "rss", "json_feed", "web_crawler", "sitemap". */
    public function type(): string;

    public function fetch(SourceRecord $source): FetchResult;
}

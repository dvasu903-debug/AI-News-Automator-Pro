<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

/**
 * Marker interface — certifies this connector fetches a well-defined
 * feed format (RSS/Atom/JSON) at a single admin-configured URL, as
 * opposed to autonomously discovering/following links (see
 * CrawlConnectorInterface). This distinction is what scopes robots.txt
 * enforcement: fetching a URL the admin explicitly configured is not
 * "crawling" in the disallow sense.
 */
interface FeedConnectorInterface extends SourceConnectorInterface
{
}

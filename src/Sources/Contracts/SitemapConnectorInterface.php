<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

/**
 * Marker interface — certifies this connector parses XML sitemaps
 * (including sitemap-index files) rather than a content feed.
 */
interface SitemapConnectorInterface extends SourceConnectorInterface
{
}

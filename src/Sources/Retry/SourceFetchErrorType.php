<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Retry;

/**
 * Classifies why a source fetch (feed, crawl, sitemap, robots.txt) failed.
 * Deliberately a Sources-owned, narrower cousin of AI\Exceptions\AIErrorType
 * — see ADR-0016: duplicating the retry ALGORITHM shape is accepted, but
 * this module does not depend on AI's exception type for a non-AI concern.
 */
enum SourceFetchErrorType: string
{
    case NetworkTimeout   = 'network_timeout';   // retryable
    case ServerError      = 'server_error';      // retryable (5xx)
    case NotFound         = 'not_found';         // non-retryable (404)
    case Forbidden        = 'forbidden';         // non-retryable (401/403)
    case RobotsDisallowed = 'robots_disallowed'; // non-retryable — compliance, not a transient failure
    case MalformedContent = 'malformed_content'; // non-retryable — retrying won't fix bad XML/JSON
    case Unknown          = 'unknown';           // non-retryable by default (safe default)

    public function isRetryable(): bool
    {
        return match ($this) {
            self::NetworkTimeout, self::ServerError => true,
            default => false,
        };
    }
}

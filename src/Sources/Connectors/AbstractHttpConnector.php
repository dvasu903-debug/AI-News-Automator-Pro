<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Connectors;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Sources\Retry\SourceFetchErrorType;
use AINewsAutomator\Sources\Exceptions\SourceFetchException;

/**
 * Shared mechanics every connector extends — mirrors AI's
 * AbstractHttpProvider: SSRF-guarded HTTP (via Security's
 * OutboundHttpValidator, never wp_remote_* directly), default HTTP-status
 * error classification into SourceFetchErrorType, and per-domain rate
 * limiting (Security's RateLimiterInterface, reused) so multiple sources
 * pointed at the same domain can't hammer it.
 */
abstract class AbstractHttpConnector
{
    public function __construct(
        protected readonly OutboundHttpValidator $http,
        protected readonly LoggerInterface $logger,
        protected readonly RateLimiterInterface $rateLimiter,
    ) {
    }

    /**
     * GET request returning the raw response body. Every connector's
     * only path to the network — no connector calls wp_remote_* or
     * OutboundHttpValidator directly.
     *
     * @param array<string, string> $headers
     *
     * @throws SourceFetchException
     */
    protected function fetchUrl(string $url, array $headers = [], int $timeoutSeconds = 20): string
    {
        $this->enforceDomainRateLimit($url);

        $response = $this->http->get($url, ['headers' => $headers, 'timeout' => $timeoutSeconds]);

        if (is_wp_error($response)) {
            throw new SourceFetchException(
                sprintf('Request to %s failed: %s', $url, $response->get_error_message()),
                SourceFetchErrorType::NetworkTimeout
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);

        if ($statusCode >= 200 && $statusCode < 300) {
            return (string) wp_remote_retrieve_body($response);
        }

        throw new SourceFetchException(
            sprintf('%s returned HTTP %d.', $url, $statusCode),
            $this->classifyHttpError($statusCode)
        );
    }

    protected function classifyHttpError(int $statusCode): SourceFetchErrorType
    {
        return match (true) {
            $statusCode === 404 => SourceFetchErrorType::NotFound,
            $statusCode === 401, $statusCode === 403 => SourceFetchErrorType::Forbidden,
            $statusCode >= 500 => SourceFetchErrorType::ServerError,
            default => SourceFetchErrorType::Unknown,
        };
    }

    private function enforceDomainRateLimit(string $url): void
    {
        $host = (string) (wp_parse_url($url)['host'] ?? $url);
        $key = 'source_domain:' . $host;

        $result = $this->rateLimiter->hit($key, 20, 60);

        if (!$result->allowed) {
            throw new SourceFetchException(
                sprintf('Rate limit exceeded for domain "%s".', $host),
                SourceFetchErrorType::NetworkTimeout
            );
        }
    }
}

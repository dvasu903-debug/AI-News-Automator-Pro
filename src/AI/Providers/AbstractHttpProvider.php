<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Providers;

use AINewsAutomator\AI\DTO\ProviderHealth;
use AINewsAutomator\AI\DTO\ProviderHealthStatus;
use AINewsAutomator\AI\Exceptions\AIErrorType;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;

/**
 * Shared mechanics every provider adapter extends: SSRF-guarded HTTP
 * (via Security's OutboundHttpValidator — no provider ever calls
 * wp_remote_* directly), default HTTP-status error classification, and a
 * lightweight rolling health signal. This is what keeps ClaudeProvider,
 * GeminiProvider, and OpenAiCompatibleProvider free of duplicated HTTP
 * boilerplate even though they differ in request/response SHAPE.
 */
abstract class AbstractHttpProvider
{
    private const HEALTH_TRANSIENT_PREFIX = 'ana_ai_health_';

    public function __construct(
        protected readonly OutboundHttpValidator $http,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Performs a POST request and returns the decoded JSON body.
     * Classifies any failure into an AIException carrying an AIErrorType
     * — this is the single point every provider's HTTP failures flow
     * through, so classification logic is never duplicated per-provider.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     *
     * @throws AIException
     */
    protected function post(string $providerId, string $url, array $headers, array $body, int $timeoutSeconds): array
    {
        $start = microtime(true);

        $response = $this->http->post($url, [
            'headers' => $headers,
            'body'    => wp_json_encode($body) ?: '{}',
            'timeout' => $timeoutSeconds,
        ]);

        $durationMs = (microtime(true) - $start) * 1000;

        if (is_wp_error($response)) {
            $this->recordFailure($providerId);
            throw new AIException(
                sprintf('HTTP request to %s failed: %s', $providerId, $response->get_error_message()),
                AIErrorType::ProviderOutage,
                $providerId
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $decoded = json_decode($rawBody, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->recordSuccess($providerId, $durationMs);
            return $decoded;
        }

        $errorType = $this->classifyHttpError($statusCode, $decoded);

        if ($errorType->isRetryable()) {
            // Transient failures don't mark the provider unhealthy on
            // their own — a single 429/5xx is expected noise, not an outage.
            $this->recordFailure($providerId);
        }

        throw new AIException(
            sprintf('%s returned HTTP %d: %s', $providerId, $statusCode, $this->extractErrorMessage($decoded)),
            $errorType,
            $providerId
        );
    }

    /**
     * Default HTTP-status classification. Subclasses may override for
     * vendor-specific nuances (e.g. distinguishing rate-limit from quota
     * via a vendor-specific error code in the body) — this default
     * covers the common REST API conventions shared by every provider
     * this module targets.
     *
     * @param array<string, mixed> $decodedBody
     */
    protected function classifyHttpError(int $statusCode, array $decodedBody): AIErrorType
    {
        return match (true) {
            $statusCode === 400 => AIErrorType::Validation,
            $statusCode === 401, $statusCode === 403 => AIErrorType::Authentication,
            $statusCode === 429 => $this->looksLikeQuotaExhaustion($decodedBody)
                ? AIErrorType::Quota
                : AIErrorType::RateLimited,
            $statusCode >= 500 => AIErrorType::ProviderOutage,
            default => AIErrorType::Unknown,
        };
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    private function looksLikeQuotaExhaustion(array $decodedBody): bool
    {
        $message = strtolower($this->extractErrorMessage($decodedBody));

        return str_contains($message, 'quota') || str_contains($message, 'insufficient_quota') || str_contains($message, 'billing');
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    private function extractErrorMessage(array $decodedBody): string
    {
        return (string) ($decodedBody['error']['message'] ?? $decodedBody['message'] ?? 'Unknown error');
    }

    /**
     * Builds a ProviderHealth from the rolling success/failure signal
     * recorded by post(). Deliberately named distinctly from
     * AIProviderInterface::healthCheck() (which each concrete provider
     * implements with zero arguments) rather than declared as an
     * overridable base method with a different signature — avoids any
     * ambiguity about PHP method-override parameter-count compatibility.
     * Concrete providers call this directly: `$this->buildHealthCheck($this->id())`.
     */
    protected function buildHealthCheck(string $providerId): ProviderHealth
    {
        $failures = (int) get_transient(self::HEALTH_TRANSIENT_PREFIX . $providerId . '_failures');
        $lastLatency = get_transient(self::HEALTH_TRANSIENT_PREFIX . $providerId . '_latency');

        $status = match (true) {
            $failures >= 5 => ProviderHealthStatus::Unavailable,
            $failures >= 2 => ProviderHealthStatus::Degraded,
            default => ProviderHealthStatus::Healthy,
        };

        return new ProviderHealth(
            providerId: $providerId,
            status: $status,
            lastLatencyMs: $lastLatency !== false ? (float) $lastLatency : null,
            checkedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            detail: $failures > 0 ? sprintf('%d recent failure(s) recorded.', $failures) : null
        );
    }

    private function recordSuccess(string $providerId, float $durationMs): void
    {
        set_transient(self::HEALTH_TRANSIENT_PREFIX . $providerId . '_failures', 0, 300);
        set_transient(self::HEALTH_TRANSIENT_PREFIX . $providerId . '_latency', $durationMs, 300);
    }

    private function recordFailure(string $providerId): void
    {
        $key = self::HEALTH_TRANSIENT_PREFIX . $providerId . '_failures';
        $current = (int) get_transient($key);
        set_transient($key, $current + 1, 300);
    }
}

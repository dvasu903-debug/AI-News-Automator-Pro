<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Metrics;

use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;

/**
 * Simple counters for security operations, stored in a single wp_options
 * map. Read by the Analytics module later. Kept intentionally lightweight —
 * these are coarse counters for dashboards, not a time-series system.
 */
final class SecurityMetrics implements SecurityMetricsInterface
{
    private const OPTION_KEY = 'ai_news_automator_security_metrics';

    public const DENIED_REQUESTS       = 'denied_requests';
    public const NONCE_FAILURES        = 'nonce_failures';
    public const SUCCESSFUL_VALIDATIONS = 'successful_validations';
    public const DECRYPT_OPERATIONS    = 'decrypt_operations';
    public const RATE_LIMIT_HITS       = 'rate_limit_hits';
    public const WEBHOOK_FAILURES      = 'webhook_failures';

    public function increment(string $metric, int $by = 1): void
    {
        $all = $this->all();
        $all[$metric] = ($all[$metric] ?? 0) + $by;
        update_option(self::OPTION_KEY, $all, false);
    }

    public function get(string $metric): int
    {
        return $this->all()[$metric] ?? 0;
    }

    public function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        $result = [];
        foreach ($stored as $key => $value) {
            $result[(string) $key] = (int) $value;
        }

        return $result;
    }
}

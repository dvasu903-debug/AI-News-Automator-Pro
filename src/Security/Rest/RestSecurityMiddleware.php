<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Rest;

use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Security\Contracts\NonceManagerInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Security\Metrics\SecurityMetrics;
use AINewsAutomator\Security\Request\RequestContext;

/**
 * Produces REST permission_callbacks that enforce Security guarantees on
 * REST endpoints. Later modules' controllers use these instead of writing
 * ad-hoc current_user_can checks, so REST endpoints get the same audited,
 * policy-driven authorization as admin-post actions.
 *
 * This is a NEW opt-in surface; it does not modify Core's AbstractRestController
 * (frozen). Security-aware controllers request a callback from here.
 */
final class RestSecurityMiddleware
{
    public function __construct(
        private readonly CapabilityGateInterface $gate,
        private readonly NonceManagerInterface $nonces,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly SecurityMetricsInterface $metrics,
        private readonly RequestContext $request,
    ) {
    }

    /**
     * A permission callback that requires the given ability. REST already
     * verifies the X-WP-Nonce cookie-nonce for logged-in requests, so this
     * focuses on ability authorization via the gate (which audits).
     *
     * @return callable(\WP_REST_Request): (true|\WP_Error)
     */
    public function requireAbility(string $ability): callable
    {
        return function (\WP_REST_Request $request) use ($ability) {
            if ($this->gate->allows($ability)) {
                return true;
            }

            return new \WP_Error(
                'ana_forbidden',
                __('You are not permitted to perform this action.', 'ai-news-automator'),
                ['status' => 403]
            );
        };
    }

    /**
     * A permission callback that additionally rate-limits per user.
     *
     * @return callable(\WP_REST_Request): (true|\WP_Error)
     */
    public function requireAbilityWithRateLimit(string $ability, string $bucket, int $limit, int $window): callable
    {
        return function (\WP_REST_Request $request) use ($ability, $bucket, $limit, $window) {
            if (!$this->gate->allows($ability)) {
                return new \WP_Error(
                    'ana_forbidden',
                    __('You are not permitted to perform this action.', 'ai-news-automator'),
                    ['status' => 403]
                );
            }

            $key = $bucket . ':user_' . $this->request->currentUserId();
            $result = $this->rateLimiter->hit($key, $limit, $window);

            if (!$result->allowed) {
                $this->metrics->increment(SecurityMetrics::RATE_LIMIT_HITS);

                return new \WP_Error(
                    'ana_rate_limited',
                    __('Rate limit exceeded. Please retry later.', 'ai-news-automator'),
                    ['status' => 429, 'retry_after' => $result->retryAfter]
                );
            }

            return true;
        };
    }
}

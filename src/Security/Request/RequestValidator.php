<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Request;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Security\Contracts\NonceManagerInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Contracts\RequestValidatorInterface;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Security\Events\RateLimitExceededEvent;
use AINewsAutomator\Security\Events\SuspiciousRequestEvent;
use AINewsAutomator\Security\Exceptions\AuthorizationException;
use AINewsAutomator\Security\Exceptions\NonceException;
use AINewsAutomator\Security\Exceptions\RateLimitException;
use AINewsAutomator\Security\Metrics\SecurityMetrics;

/**
 * One guard to validate a state-changing request: nonce first (cheap,
 * catches CSRF/replay), then capability (via the gate, which audits), then
 * optional rate limit. Fails closed — throws on the first failed check —
 * so a handler that calls this once is protected against CSRF, privilege
 * escalation, and abuse in a single line.
 *
 * A failed nonce emits a SuspiciousRequestEvent (a forged/replayed nonce is
 * rarely an honest mistake), feeding ThreatDetector.
 */
final class RequestValidator implements RequestValidatorInterface
{
    public function __construct(
        private readonly NonceManagerInterface $nonces,
        private readonly CapabilityGateInterface $gate,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly SecurityMetricsInterface $metrics,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly RequestContext $request,
    ) {
    }

    public function validate(
        string $ability,
        string $nonceAction,
        ?string $nonceValue = null,
        ?string $rateLimitKey = null
    ): void {
        $nonce = $nonceValue ?? $this->readNonce($nonceAction);

        if (!$this->nonces->verify($nonce, $nonceAction)) {
            $this->metrics->increment(SecurityMetrics::NONCE_FAILURES);

            $this->events->dispatch(new SuspiciousRequestEvent(
                $this->metadataFactory->create('Security', ['action' => $nonceAction]),
                kind: 'nonce_failure',
                detail: sprintf('Nonce verification failed for action "%s".', $nonceAction),
                ip: $this->request->ip(),
            ));

            throw NonceException::forAction($nonceAction);
        }

        // Capability check (this audits + emits its own event on denial).
        if (!$this->gate->allows($ability)) {
            throw AuthorizationException::forAbility($ability);
        }

        if ($rateLimitKey !== null) {
            $result = $this->rateLimiter->hit($rateLimitKey, 30, 60);

            if (!$result->allowed) {
                $this->metrics->increment(SecurityMetrics::RATE_LIMIT_HITS);

                $this->events->dispatch(new RateLimitExceededEvent(
                    $this->metadataFactory->create('Security', ['key' => $rateLimitKey]),
                    key: $rateLimitKey,
                    limit: 30,
                    window: 60,
                    ip: $this->request->ip(),
                ));

                throw new RateLimitException(
                    sprintf('Rate limit exceeded for "%s".', $rateLimitKey),
                    $result->retryAfter
                );
            }
        }

        $this->metrics->increment(SecurityMetrics::SUCCESSFUL_VALIDATIONS);
    }

    private function readNonce(string $nonceAction): string
    {
        $field = $this->nonces->fieldName($nonceAction);

        // Accept the nonce from POST field or the REST header convention.
        if (isset($_POST[$field]) && is_string($_POST[$field])) {
            return sanitize_text_field(wp_unslash($_POST[$field]));
        }

        if (isset($_SERVER['HTTP_X_WP_NONCE']) && is_string($_SERVER['HTTP_X_WP_NONCE'])) {
            return sanitize_text_field((string) $_SERVER['HTTP_X_WP_NONCE']);
        }

        return '';
    }
}

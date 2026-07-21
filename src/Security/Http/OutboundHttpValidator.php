<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Http;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Security\Contracts\UrlGuardInterface;

/**
 * Performs outbound HTTP only after the URL clears the SSRF guard. Wraps
 * wp_safe_remote_get/post (WordPress's own SSRF-hardened HTTP functions)
 * and adds our guard on top, since wp_safe_remote_* alone does not resolve-
 * and-validate against the full private-range set the way UrlGuard does.
 *
 * Later modules that fetch user-supplied URLs (Sources) call this instead of
 * wp_remote_get directly, so SSRF protection is not optional or forgettable.
 */
final class OutboundHttpValidator
{
    public function __construct(
        private readonly UrlGuardInterface $guard,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args wp_remote_get args.
     * @return array<string, mixed>|\WP_Error
     */
    public function get(string $url, array $args = []): array|\WP_Error
    {
        $inspection = $this->guard->inspect($url);

        if (!$inspection->allowed) {
            $this->logger->warning('Blocked outbound request to unsafe URL: {reason}', [
                'reason' => $inspection->reasonCode,
                'url'    => $url,
            ]);

            return new \WP_Error('ana_ssrf_blocked', sprintf(
                'Outbound request blocked: %s',
                $inspection->message
            ));
        }

        return wp_safe_remote_get($url, $args);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public function post(string $url, array $args = []): array|\WP_Error
    {
        $inspection = $this->guard->inspect($url);

        if (!$inspection->allowed) {
            $this->logger->warning('Blocked outbound request to unsafe URL: {reason}', [
                'reason' => $inspection->reasonCode,
                'url'    => $url,
            ]);

            return new \WP_Error('ana_ssrf_blocked', sprintf(
                'Outbound request blocked: %s',
                $inspection->message
            ));
        }

        return wp_safe_remote_post($url, $args);
    }
}

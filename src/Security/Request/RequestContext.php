<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Request;

/**
 * Safely reads request-scoped identity data: current user, client IP, and
 * user agent. Centralized so every Security component derives these the
 * same way, including the careful IP handling below.
 *
 * IP handling note: proxy headers (X-Forwarded-For) are trivially spoofable
 * unless the site sits behind a trusted proxy that overwrites them. By
 * default we use REMOTE_ADDR only (the TCP peer, unspoofable at the network
 * layer). Sites genuinely behind a trusted proxy can opt into a forwarded
 * header via the 'ai_news_automator_trusted_proxy_header' filter — but the
 * default is the safe one, because trusting XFF by default would let an
 * attacker forge their apparent IP to evade IP-based blocks and rate limits.
 */
final class RequestContext
{
    public function currentUserId(): int
    {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    public function currentUserLogin(): string
    {
        if (!function_exists('wp_get_current_user')) {
            return '';
        }

        $user = wp_get_current_user();

        return $user instanceof \WP_User ? (string) $user->user_login : '';
    }

    public function ip(): string
    {
        $trustedHeader = apply_filters('ai_news_automator_trusted_proxy_header', '');

        if (is_string($trustedHeader) && $trustedHeader !== '') {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $trustedHeader));
            if (!empty($_SERVER[$serverKey])) {
                // Take the first hop in a comma-separated forwarded chain.
                $candidate = trim(explode(',', (string) $_SERVER[$serverKey])[0]);
                if ($this->isValidIp($candidate)) {
                    return $candidate;
                }
            }
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        return $this->isValidIp($remote) ? $remote : 'unknown';
    }

    public function userAgent(): string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        // Bound the length and strip control chars — a UA string is attacker-
        // controlled and gets stored in the audit log.
        $ua = substr($ua, 0, 512);

        return function_exists('sanitize_text_field') ? sanitize_text_field($ua) : trim($ua);
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Http;

/**
 * Result of inspecting a URL for SSRF safety. Carries the allow/block
 * decision, a machine-readable reason code, a human message, and the
 * resolved IP (so a caller can pin it for the actual connection to defeat
 * DNS rebinding).
 */
final class UrlInspectionResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reasonCode,
        public readonly string $message,
        public readonly ?string $resolvedIp,
    ) {
    }

    public static function allowed(string $resolvedIp): self
    {
        return new self(true, 'ok', 'URL is permitted.', $resolvedIp);
    }

    public static function blocked(string $reasonCode, string $message, ?string $resolvedIp = null): self
    {
        return new self(false, $reasonCode, $message, $resolvedIp);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

use AINewsAutomator\Security\Http\UrlInspectionResult;

/**
 * Guards outbound HTTP against SSRF. Validates scheme, resolves the host,
 * and rejects private/loopback/link-local/metadata destinations. Exposes
 * the resolved IP so a caller can pin it for the actual request and defeat
 * DNS-rebinding (resolve-then-connect-to-different-IP) attacks.
 */
interface UrlGuardInterface
{
    /**
     * Inspects a URL without fetching it. Never throws — the result object
     * carries allowed/blocked plus the reason and resolved IP.
     */
    public function inspect(string $url): UrlInspectionResult;

    /**
     * Convenience: true if the URL is safe to fetch.
     */
    public function isAllowed(string $url): bool;
}

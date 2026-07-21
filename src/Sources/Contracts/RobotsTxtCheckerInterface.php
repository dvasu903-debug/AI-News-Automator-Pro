<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

interface RobotsTxtCheckerInterface
{
    public function isAllowed(string $url, string $userAgent): bool;

    /**
     * @return list<string> Sitemap URLs declared via "Sitemap:" directives in robots.txt.
     */
    public function discoveredSitemaps(string $domain): array;
}

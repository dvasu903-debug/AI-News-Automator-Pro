<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Robots;

/**
 * Parsed robots.txt directives for one matched user-agent group. A
 * deliberate simplification of RFC 9309: prefix-based Allow/Disallow
 * matching only (no wildcard `*`/`$` pattern support) — sufficient for
 * the vast majority of real-world robots.txt files, and safer to
 * under-permit than to mis-implement wildcard matching incorrectly.
 */
final class RobotsRules
{
    /**
     * @param list<string> $disallow
     * @param list<string> $allow
     * @param list<string> $sitemaps
     */
    public function __construct(
        public readonly array $disallow,
        public readonly array $allow,
        public readonly array $sitemaps,
        public readonly bool $permissive = false,
    ) {
    }

    public static function permissive(): self
    {
        return new self([], [], [], permissive: true);
    }

    public static function denyAll(): self
    {
        return new self(['/'], [], []);
    }

    public function isPathAllowed(string $path): bool
    {
        if ($this->permissive) {
            return true;
        }

        $bestDisallow = -1;
        $bestAllow = -1;

        foreach ($this->disallow as $rule) {
            if ($rule !== '' && str_starts_with($path, $rule)) {
                $bestDisallow = max($bestDisallow, strlen($rule));
            }
        }

        foreach ($this->allow as $rule) {
            if ($rule !== '' && str_starts_with($path, $rule)) {
                $bestAllow = max($bestAllow, strlen($rule));
            }
        }

        if ($bestDisallow === -1) {
            return true;
        }

        // A more specific (longer) Allow rule overrides a Disallow rule.
        return $bestAllow >= $bestDisallow;
    }
}

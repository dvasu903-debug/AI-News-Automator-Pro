<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources;

use AINewsAutomator\Sources\Robots\RobotsRules;
use PHPUnit\Framework\TestCase;

final class RobotsRulesTest extends TestCase
{
    public function test_permissive_allows_everything(): void
    {
        $rules = RobotsRules::permissive();
        $this->assertTrue($rules->isPathAllowed('/anything'));
        $this->assertTrue($rules->isPathAllowed('/private/secret'));
    }

    public function test_deny_all_blocks_everything(): void
    {
        $rules = RobotsRules::denyAll();
        $this->assertFalse($rules->isPathAllowed('/anything'));
        $this->assertFalse($rules->isPathAllowed('/'));
    }

    public function test_no_matching_disallow_rule_allows_by_default(): void
    {
        $rules = new RobotsRules(['/private'], [], []);
        $this->assertTrue($rules->isPathAllowed('/public/article'));
    }

    public function test_matching_disallow_rule_blocks(): void
    {
        $rules = new RobotsRules(['/private'], [], []);
        $this->assertFalse($rules->isPathAllowed('/private/secret'));
    }

    public function test_longer_allow_rule_overrides_shorter_disallow(): void
    {
        // Standard robots.txt semantics: most specific (longest) rule wins.
        $rules = new RobotsRules(['/private'], ['/private/public-subsection'], []);

        $this->assertTrue($rules->isPathAllowed('/private/public-subsection/page'));
        $this->assertFalse($rules->isPathAllowed('/private/other'));
    }

    public function test_shorter_allow_rule_does_not_override_longer_disallow(): void
    {
        $rules = new RobotsRules(['/private/secret'], ['/private'], []);

        $this->assertFalse($rules->isPathAllowed('/private/secret/page'));
        $this->assertTrue($rules->isPathAllowed('/private/other'));
    }

    public function test_deny_all_via_root_disallow(): void
    {
        $rules = new RobotsRules(['/'], [], []);
        $this->assertFalse($rules->isPathAllowed('/anything'));
    }

    public function test_sitemaps_are_exposed(): void
    {
        $rules = new RobotsRules([], [], ['https://example.test/sitemap.xml']);
        $this->assertSame(['https://example.test/sitemap.xml'], $rules->sitemaps);
    }
}

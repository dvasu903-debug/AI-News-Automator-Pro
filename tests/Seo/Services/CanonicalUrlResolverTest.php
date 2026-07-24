<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Services;

use AINewsAutomator\Seo\Services\CanonicalUrlResolver;
use PHPUnit\Framework\TestCase;

final class CanonicalUrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_permalinks'] = [];
    }

    public function test_resolves_a_configured_permalink(): void
    {
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/my-post';

        $this->assertSame('https://example.test/my-post', (new CanonicalUrlResolver())->resolve(1));
    }

    public function test_returns_null_when_permalink_unresolvable(): void
    {
        $this->assertNull((new CanonicalUrlResolver())->resolve(999));
    }
}

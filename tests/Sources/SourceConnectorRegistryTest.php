<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources;

use AINewsAutomator\Sources\Registry\SourceConnectorRegistry;
use AINewsAutomator\Tests\Sources\Fakes\FakeSourceConnector;
use PHPUnit\Framework\TestCase;

final class SourceConnectorRegistryTest extends TestCase
{
    public function test_register_and_resolve_by_type(): void
    {
        $registry = new SourceConnectorRegistry();
        $connector = new FakeSourceConnector('rss');
        $registry->register($connector);

        $this->assertSame($connector, $registry->forType('rss'));
    }

    public function test_unregistered_type_returns_null(): void
    {
        $registry = new SourceConnectorRegistry();
        $this->assertNull($registry->forType('nonexistent'));
    }

    public function test_all_returns_every_registered_connector(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));
        $registry->register(new FakeSourceConnector('sitemap'));

        $this->assertCount(2, $registry->all());
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources;

use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Sources\Exceptions\SourceValidationException;
use AINewsAutomator\Sources\Registry\SourceConnectorRegistry;
use AINewsAutomator\Sources\Validation\SourceValidator;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Storage\Entities\SourceRecord;
use AINewsAutomator\Tests\Sources\Fakes\FakeSourceConnector;
use PHPUnit\Framework\TestCase;

final class SourceValidatorTest extends TestCase
{
    private function source(string $type = 'rss', array $config = ['url' => 'https://example.test/feed']): SourceRecord
    {
        return new SourceRecord(
            id: null,
            name: 'Test Source',
            type: $type,
            config: $config,
            enabled: true,
            lastFetchedAt: null,
            lastError: null,
            createdAt: EntityDates::now(),
            updatedAt: EntityDates::now(),
        );
    }

    public function test_valid_source_passes(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));

        (new SourceValidator($registry))->validateSource($this->source());
        $this->assertTrue(true);
    }

    public function test_empty_name_fails(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));

        $source = new SourceRecord(null, '', 'rss', ['url' => 'https://x.test'], true, null, null, EntityDates::now(), EntityDates::now());

        $this->expectException(SourceValidationException::class);
        (new SourceValidator($registry))->validateSource($source);
    }

    public function test_unregistered_type_fails(): void
    {
        $registry = new SourceConnectorRegistry(); // nothing registered

        $this->expectException(SourceValidationException::class);
        (new SourceValidator($registry))->validateSource($this->source());
    }

    public function test_missing_url_and_seed_url_fails(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));

        $this->expectException(SourceValidationException::class);
        (new SourceValidator($registry))->validateSource($this->source(config: []));
    }

    public function test_web_crawler_with_seed_url_passes(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('web_crawler'));

        (new SourceValidator($registry))->validateSource($this->source('web_crawler', ['seed_url' => 'https://x.test']));
        $this->assertTrue(true);
    }

    public function test_item_with_empty_url_fails(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));

        $this->expectException(SourceValidationException::class);
        (new SourceValidator($registry))->validateItem(new NormalizedItem(url: ''));
    }

    public function test_item_with_malformed_url_fails(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));

        $this->expectException(SourceValidationException::class);
        (new SourceValidator($registry))->validateItem(new NormalizedItem(url: 'not a url'));
    }

    public function test_item_with_valid_url_passes(): void
    {
        $registry = new SourceConnectorRegistry();
        $registry->register(new FakeSourceConnector('rss'));

        (new SourceValidator($registry))->validateItem(new NormalizedItem(url: 'https://example.test/article'));
        $this->assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use PHPUnit\Framework\TestCase;

final class OptionBackedLoggerTest extends TestCase
{
    private CorrelationContext $correlation;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $this->correlation = new CorrelationContext('fixed-correlation-id');
    }

    private function makeLogger(Environment $env = Environment::Development): OptionBackedLogger
    {
        return new OptionBackedLogger($this->correlation, $env);
    }

    public function test_logged_message_is_retrievable(): void
    {
        $logger = $this->makeLogger();
        $logger->info('Pipeline run started');

        $recent = $logger->recent();

        $this->assertCount(1, $recent);
        $this->assertSame('info', $recent[0]['level']);
        $this->assertSame('Pipeline run started', $recent[0]['message']);
    }

    public function test_entry_carries_correlation_id(): void
    {
        $logger = $this->makeLogger();
        $logger->info('anything');

        $this->assertSame('fixed-correlation-id', $logger->recent()[0]['correlation_id']);
    }

    public function test_structured_context_is_preserved_raw(): void
    {
        $logger = $this->makeLogger();
        $logger->error('Failed with status {status}', ['status' => 500, 'url' => 'https://x.test']);

        $entry = $logger->recent()[0];

        $this->assertSame('Failed with status 500', $entry['message']);
        $this->assertSame(['status' => 500, 'url' => 'https://x.test'], $entry['context']);
    }

    public function test_debug_is_suppressed_in_production(): void
    {
        $logger = $this->makeLogger(Environment::Production);
        $logger->debug('verbose detail');

        $this->assertCount(0, $logger->recent());
    }

    public function test_debug_is_kept_in_development(): void
    {
        $logger = $this->makeLogger(Environment::Development);
        $logger->debug('verbose detail');

        $this->assertCount(1, $logger->recent());
    }

    public function test_invalid_level_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeLogger()->log('not-a-level', 'message');
    }

    public function test_recent_returns_newest_first_and_respects_limit(): void
    {
        $logger = $this->makeLogger();
        $logger->info('first');
        $logger->info('second');
        $logger->info('third');

        $recent = $logger->recent(2);

        $this->assertCount(2, $recent);
        $this->assertSame('third', $recent[0]['message']);
        $this->assertSame('second', $recent[1]['message']);
    }
}

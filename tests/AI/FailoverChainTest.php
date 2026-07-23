<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\Contracts\ChatProviderInterface;
use AINewsAutomator\AI\DTO\ProviderHealthStatus;
use AINewsAutomator\AI\Manager\FailoverChain;
use AINewsAutomator\AI\Registry\ProviderRegistry;
use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

final class FailoverChainTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
    }

    private function logger(): OptionBackedLogger
    {
        return new OptionBackedLogger(new CorrelationContext('test'), Environment::Development);
    }

    public function test_selects_only_capability_eligible_provider(): void
    {
        $config = new OptionBackedConfigRepository([]);
        $registry = new ProviderRegistry($config);
        $a = new FakeChatProvider('a');
        $registry->register($a);

        $chain = new FailoverChain($registry, $config, $this->logger());
        $result = $chain->nextEligible(ChatProviderInterface::class, []);

        $this->assertSame('a', $result?->id());
    }

    public function test_excludes_already_tried_providers(): void
    {
        $config = new OptionBackedConfigRepository([]);
        $registry = new ProviderRegistry($config);
        $registry->register(new FakeChatProvider('a'));
        $registry->register(new FakeChatProvider('b'));

        $chain = new FailoverChain($registry, $config, $this->logger());
        $result = $chain->nextEligible(ChatProviderInterface::class, ['a']);

        $this->assertSame('b', $result?->id());
    }

    public function test_respects_configured_priority_order(): void
    {
        $config = new OptionBackedConfigRepository(['ai' => ['failover' => ['priority' => ['b', 'a']]]]);
        $registry = new ProviderRegistry($config);
        $registry->register(new FakeChatProvider('a'));
        $registry->register(new FakeChatProvider('b'));

        $chain = new FailoverChain($registry, $config, $this->logger());
        $result = $chain->nextEligible(ChatProviderInterface::class, []);

        $this->assertSame('b', $result?->id(), 'b is configured with higher priority than a.');
    }

    public function test_skips_unhealthy_provider(): void
    {
        $config = new OptionBackedConfigRepository([]);
        $registry = new ProviderRegistry($config);

        $unhealthy = new FakeChatProvider('a');
        $unhealthy->setHealth(ProviderHealthStatus::Unavailable);
        $registry->register($unhealthy);
        $registry->register(new FakeChatProvider('b'));

        $chain = new FailoverChain($registry, $config, $this->logger());
        $result = $chain->nextEligible(ChatProviderInterface::class, []);

        $this->assertSame('b', $result?->id(), 'Unavailable provider must not be selected.');
    }

    public function test_respects_administrator_exclusion_policy(): void
    {
        $config = new OptionBackedConfigRepository(['ai' => ['failover' => ['excluded_providers' => ['a']]]]);
        $registry = new ProviderRegistry($config);
        $registry->register(new FakeChatProvider('a'));
        $registry->register(new FakeChatProvider('b'));

        $chain = new FailoverChain($registry, $config, $this->logger());
        $result = $chain->nextEligible(ChatProviderInterface::class, []);

        $this->assertSame('b', $result?->id(), 'Admin-excluded provider must never be selected.');
    }

    public function test_returns_null_when_no_eligible_provider_remains(): void
    {
        $config = new OptionBackedConfigRepository([]);
        $registry = new ProviderRegistry($config);
        $registry->register(new FakeChatProvider('a'));

        $chain = new FailoverChain($registry, $config, $this->logger());
        $result = $chain->nextEligible(ChatProviderInterface::class, ['a']);

        $this->assertNull($result);
    }
}

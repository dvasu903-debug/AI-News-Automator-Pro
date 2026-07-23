<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\Contracts\ChatProviderInterface;
use AINewsAutomator\AI\Registry\ProviderRegistry;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
    }

    public function test_register_and_get_round_trip(): void
    {
        $registry = new ProviderRegistry(new OptionBackedConfigRepository([]));
        $provider = new FakeChatProvider('claude');
        $registry->register($provider);

        $this->assertSame($provider, $registry->get('claude'));
    }

    public function test_get_returns_null_for_unregistered_id(): void
    {
        $registry = new ProviderRegistry(new OptionBackedConfigRepository([]));
        $this->assertNull($registry->get('nonexistent'));
    }

    public function test_all_implementing_filters_by_interface(): void
    {
        $registry = new ProviderRegistry(new OptionBackedConfigRepository([]));
        $registry->register(new FakeChatProvider('a'));
        $registry->register(new FakeChatProvider('b'));

        $this->assertCount(2, $registry->allImplementing(ChatProviderInterface::class));
    }

    public function test_default_for_reads_configured_capability_default(): void
    {
        $config = new OptionBackedConfigRepository(['ai' => ['defaults' => ['chat' => 'claude']]]);
        $registry = new ProviderRegistry($config);
        $registry->register(new FakeChatProvider('claude'));

        $default = $registry->defaultFor('chat');

        $this->assertSame('claude', $default?->id());
    }

    public function test_default_for_returns_null_when_unconfigured(): void
    {
        $registry = new ProviderRegistry(new OptionBackedConfigRepository([]));
        $this->assertNull($registry->defaultFor('chat'));
    }

    public function test_default_for_returns_null_when_configured_provider_not_registered(): void
    {
        $config = new OptionBackedConfigRepository(['ai' => ['defaults' => ['chat' => 'nonexistent']]]);
        $registry = new ProviderRegistry($config);

        $this->assertNull($registry->defaultFor('chat'));
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\Cache\TransientResponseCache;
use PHPUnit\Framework\TestCase;

final class TransientResponseCacheTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_transients'] = [];
    }

    public function test_miss_returns_null(): void
    {
        $cache = new TransientResponseCache();
        $this->assertNull($cache->get('nonexistent'));
    }

    public function test_set_then_get_round_trips(): void
    {
        $cache = new TransientResponseCache();
        $cache->set('key', ['a' => 1], 60);

        $this->assertSame(['a' => 1], $cache->get('key'));
    }

    public function test_forget_removes_entry(): void
    {
        $cache = new TransientResponseCache();
        $cache->set('key', 'value', 60);
        $cache->forget('key');

        $this->assertNull($cache->get('key'));
    }

    public function test_different_keys_do_not_collide(): void
    {
        $cache = new TransientResponseCache();
        $cache->set('key-a', 'value-a', 60);
        $cache->set('key-b', 'value-b', 60);

        $this->assertSame('value-a', $cache->get('key-a'));
        $this->assertSame('value-b', $cache->get('key-b'));
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\Secrets\KeyProvider;
use PHPUnit\Framework\TestCase;

final class KeyProviderTest extends TestCase
{
    public function test_derives_32_byte_key(): void
    {
        $key = (new KeyProvider('v1'))->currentKey();
        $this->assertSame(32, strlen($key));
    }

    public function test_same_key_id_is_deterministic(): void
    {
        $a = (new KeyProvider('v1'))->keyFor('v1');
        $b = (new KeyProvider('v1'))->keyFor('v1');
        $this->assertSame($a, $b);
    }

    public function test_different_key_ids_produce_different_keys(): void
    {
        $provider = new KeyProvider('v1');
        $this->assertNotSame($provider->keyFor('v1'), $provider->keyFor('v2'));
    }

    public function test_current_key_id_is_reported(): void
    {
        $this->assertSame('v2', (new KeyProvider('v2'))->currentKeyId());
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\Http\UrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * SSRF tests. These use literal-IP hosts (no DNS needed) so they run
 * deterministically offline. DNS-rebinding and hostname-resolution paths
 * are exercised in integration tests where real resolution is available.
 */
final class UrlGuardTest extends TestCase
{
    private UrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new UrlGuard();
    }

    public function test_allows_public_ip(): void
    {
        // 93.184.216.34 (example.com's historical IP) is a public address.
        $this->assertTrue($this->guard->inspect('https://93.184.216.34/feed')->allowed);
    }

    /**
     * @dataProvider blockedUrlProvider
     */
    public function test_blocks_unsafe_urls(string $url, string $expectedReason): void
    {
        $result = $this->guard->inspect($url);

        $this->assertFalse($result->allowed, "Expected {$url} to be blocked.");
        $this->assertSame($expectedReason, $result->reasonCode);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockedUrlProvider(): array
    {
        return [
            'loopback ipv4'        => ['http://127.0.0.1/', 'private_ip'],
            'loopback name-as-ip'  => ['http://127.0.0.53/', 'private_ip'],
            'private 10/8'         => ['http://10.0.0.5/', 'private_ip'],
            'private 172.16/12'    => ['http://172.16.0.1/', 'private_ip'],
            'private 192.168/16'   => ['http://192.168.1.1/', 'private_ip'],
            'link-local 169.254'   => ['http://169.254.1.1/', 'private_ip'],
            'cloud metadata'       => ['http://169.254.169.254/latest/meta-data/', 'private_ip'],
            'ipv6 loopback'        => ['http://[::1]/', 'private_ip'],
            'ipv6 unique-local'    => ['http://[fc00::1]/', 'private_ip'],
            'ipv6 link-local'      => ['http://[fe80::1]/', 'private_ip'],
            'non-http scheme file' => ['file:///etc/passwd', 'scheme'],
            'non-http scheme gopher' => ['gopher://127.0.0.1/', 'scheme'],
            'embedded credentials' => ['http://user:pass@93.184.216.34/', 'credentials'],
            'malformed'            => ['not a url', 'malformed'],
        ];
    }

    public function test_decimal_ip_notation_does_not_resolve_to_an_allowed_public_address(): void
    {
        // 2130706433 == 127.0.0.1 in decimal. Depending on the platform
        // resolver, "2130706433" as a hostname either fails to resolve
        // (blocked: unresolvable) or resolves to loopback (blocked:
        // private_ip). Either way it must NOT be allowed. We assert the
        // safe invariant rather than a resolver-specific reason code.
        $result = $this->guard->inspect('http://2130706433/');
        $this->assertFalse($result->allowed);
    }

    public function test_is_allowed_convenience_matches_inspect(): void
    {
        $this->assertFalse($this->guard->isAllowed('http://127.0.0.1/'));
        $this->assertTrue($this->guard->isAllowed('https://93.184.216.34/'));
    }
}

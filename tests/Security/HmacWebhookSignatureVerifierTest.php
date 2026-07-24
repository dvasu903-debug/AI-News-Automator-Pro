<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\Webhook\HmacWebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class HmacWebhookSignatureVerifierTest extends TestCase
{
    private HmacWebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new HmacWebhookSignatureVerifier();
    }

    public function test_valid_sha256_signature_verifies(): void
    {
        $payload = '{"event":"published"}';
        $secret = 'shared-secret';
        $signature = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->verifier->verify($payload, $signature, $secret, 'sha256'));
    }

    public function test_valid_sha512_signature_verifies(): void
    {
        $payload = 'body';
        $secret = 'k';
        $signature = hash_hmac('sha512', $payload, $secret);

        $this->assertTrue($this->verifier->verify($payload, $signature, $secret, 'sha512'));
    }

    public function test_prefixed_signature_is_accepted(): void
    {
        $payload = 'body';
        $secret = 'k';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->verifier->verify($payload, $signature, $secret, 'sha256'));
    }

    public function test_tampered_payload_fails(): void
    {
        $secret = 'k';
        $signature = hash_hmac('sha256', 'original', $secret);

        $this->assertFalse($this->verifier->verify('tampered', $signature, $secret, 'sha256'));
    }

    public function test_wrong_secret_fails(): void
    {
        $payload = 'body';
        $signature = hash_hmac('sha256', $payload, 'right-secret');

        $this->assertFalse($this->verifier->verify($payload, $signature, 'wrong-secret', 'sha256'));
    }

    public function test_replayed_signature_from_different_payload_fails(): void
    {
        // A captured signature for message A must not validate message B.
        $secret = 'k';
        $sigForA = hash_hmac('sha256', 'message-A', $secret);

        $this->assertFalse($this->verifier->verify('message-B', $sigForA, $secret, 'sha256'));
    }

    public function test_unsupported_algorithm_returns_false(): void
    {
        $this->assertFalse($this->verifier->verify('p', 'sig', 'secret', 'md5'));
    }

    public function test_empty_signature_or_secret_returns_false(): void
    {
        $this->assertFalse($this->verifier->verify('p', '', 'secret', 'sha256'));
        $this->assertFalse($this->verifier->verify('p', 'sig', '', 'sha256'));
    }

    public function test_supported_algorithms_listed(): void
    {
        $this->assertSame(['sha256', 'sha512'], $this->verifier->supportedAlgorithms());
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\Exceptions\EncryptionException;
use AINewsAutomator\Security\Secrets\EncryptedPayload;
use AINewsAutomator\Security\Secrets\KeyProvider;
use AINewsAutomator\Security\Secrets\SodiumEncryptor;
use PHPUnit\Framework\TestCase;

final class SodiumEncryptorTest extends TestCase
{
    private function makeEncryptor(string $keyId = 'v1'): SodiumEncryptor
    {
        return new SodiumEncryptor(new KeyProvider($keyId));
    }

    public function test_round_trip_returns_original_plaintext(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available.');
        }

        $encryptor = $this->makeEncryptor();
        $secret = 'sk-super-secret-api-key-123';

        $payload = $encryptor->encrypt($secret);
        $this->assertSame($secret, $encryptor->decrypt($payload));
    }

    public function test_payload_carries_algorithm_and_key_metadata(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available.');
        }

        $payload = $this->makeEncryptor('v1')->encrypt('x');

        $this->assertSame('xsalsa20poly1305', $payload->algorithm);
        $this->assertSame('v1', $payload->keyId);
        $this->assertSame(1, $payload->version);
    }

    public function test_tampered_ciphertext_fails_authentication(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available.');
        }

        $encryptor = $this->makeEncryptor();
        $payload = $encryptor->encrypt('sensitive');

        // Flip one bit of the ciphertext.
        $tampered = $payload->ciphertext;
        $tampered[0] = chr(ord($tampered[0]) ^ 0x01);

        $mutated = new EncryptedPayload(
            $payload->version,
            $payload->algorithm,
            $payload->keyId,
            $payload->nonce,
            $tampered
        );

        $this->expectException(EncryptionException::class);
        $encryptor->decrypt($mutated);
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available.');
        }

        $payload = $this->makeEncryptor('v1')->encrypt('data');

        // Different key id => different derived key => auth failure.
        $otherKeyEncryptor = $this->makeEncryptor('v1');
        $mutated = new EncryptedPayload(
            $payload->version,
            $payload->algorithm,
            'v2', // decryptor will derive a different key for 'v2'
            $payload->nonce,
            $payload->ciphertext
        );

        $this->expectException(EncryptionException::class);
        $otherKeyEncryptor->decrypt($mutated);
    }

    public function test_unsupported_algorithm_is_rejected(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available.');
        }

        $payload = new EncryptedPayload(1, 'aes-ecb-broken', 'v1', str_repeat("\0", 24), 'x');

        $this->expectException(EncryptionException::class);
        $this->makeEncryptor()->decrypt($payload);
    }

    public function test_malformed_storage_string_throws(): void
    {
        $this->expectException(EncryptionException::class);
        EncryptedPayload::fromStorage('not-json');
    }

    public function test_storage_round_trip_preserves_payload(): void
    {
        $original = new EncryptedPayload(1, 'xsalsa20poly1305', 'v1', 'noncebytes', 'cipherbytes');
        $restored = EncryptedPayload::fromStorage($original->toStorage());

        $this->assertSame($original->version, $restored->version);
        $this->assertSame($original->algorithm, $restored->algorithm);
        $this->assertSame($original->keyId, $restored->keyId);
        $this->assertSame($original->nonce, $restored->nonce);
        $this->assertSame($original->ciphertext, $restored->ciphertext);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Webhook;

use AINewsAutomator\Security\Contracts\WebhookSignatureVerifierInterface;

/**
 * Ed25519 webhook signature verification via libsodium's
 * crypto_sign_verify_detached (PHP 8.2 core). Unlike HMAC (shared secret),
 * Ed25519 is asymmetric: the sender signs with a private key, we verify with
 * their public key, so a compromise of our stored verification key does not
 * let an attacker forge signatures.
 *
 * The "secret" parameter here is the sender's public key (raw or hex).
 * Signature is expected as hex or base64. Verification is inherently
 * constant-time within libsodium.
 */
final class Ed25519WebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function supportedAlgorithms(): array
    {
        return ['ed25519'];
    }

    public function verify(string $payload, string $signature, string $secret, string $algorithm): bool
    {
        if (strtolower($algorithm) !== 'ed25519') {
            return false;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $publicKey = $this->decodeKey($secret);
        $sig = $this->decodeSignature($signature);

        if ($publicKey === null || $sig === null) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, $payload, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    private function decodeKey(string $key): ?string
    {
        // Accept hex or raw bytes.
        if (ctype_xdigit($key) && strlen($key) % 2 === 0) {
            $decoded = @hex2bin($key);
            return $decoded === false ? null : $decoded;
        }

        return $key;
    }

    private function decodeSignature(string $signature): ?string
    {
        if (ctype_xdigit($signature) && strlen($signature) % 2 === 0) {
            $decoded = @hex2bin($signature);
            return $decoded === false ? null : $decoded;
        }

        $base64 = base64_decode($signature, true);

        return $base64 === false ? null : $base64;
    }
}

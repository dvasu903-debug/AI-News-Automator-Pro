<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Webhook;

use AINewsAutomator\Security\Contracts\WebhookSignatureVerifierInterface;

/**
 * HMAC-based webhook signature verification (sha256, sha512). Uses
 * hash_equals for constant-time comparison so an attacker cannot recover a
 * valid signature byte-by-byte via timing measurement.
 *
 * Future-ready: no endpoint consumes this yet. When Module 12 (Social) or a
 * publishing integration receives webhooks, it verifies them through the
 * WebhookSignatureVerifierInterface — provider-agnostic, so a provider using
 * sha256 and one using sha512 both work without endpoint code changes.
 */
final class HmacWebhookSignatureVerifier implements WebhookSignatureVerifierInterface
{
    private const SUPPORTED = ['sha256', 'sha512'];

    public function supportedAlgorithms(): array
    {
        return self::SUPPORTED;
    }

    public function verify(string $payload, string $signature, string $secret, string $algorithm): bool
    {
        $algorithm = strtolower($algorithm);

        if (!in_array($algorithm, self::SUPPORTED, true)) {
            return false;
        }

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac($algorithm, $payload, $secret);

        // Normalize a possible "sha256=..." prefix some providers send.
        $provided = $signature;
        if (str_contains($provided, '=')) {
            $pieces = explode('=', $provided, 2);
            if (in_array(strtolower($pieces[0]), self::SUPPORTED, true)) {
                $provided = $pieces[1];
            }
        }

        return hash_equals($expected, $provided);
    }
}

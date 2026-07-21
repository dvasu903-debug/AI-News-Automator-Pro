<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

/**
 * Provider-agnostic webhook signature verification. Implementations cover
 * a family of algorithms (e.g. HMAC sha256/sha512, or Ed25519). All
 * comparisons MUST be constant-time to avoid timing side channels.
 *
 * Future-ready: no endpoint consumes this yet, but Social/publishing
 * integrations (Module 12) will verify inbound webhooks through it.
 */
interface WebhookSignatureVerifierInterface
{
    /**
     * @return list<string> Algorithm identifiers this verifier supports,
     *                      e.g. ["sha256", "sha512"].
     */
    public function supportedAlgorithms(): array;

    /**
     * Constant-time verification of a payload signature.
     *
     * @param string $payload   Raw request body.
     * @param string $signature Signature as received (hex or base64 per algorithm).
     * @param string $secret    Shared secret (HMAC) or public key (Ed25519).
     * @param string $algorithm One of supportedAlgorithms().
     */
    public function verify(string $payload, string $signature, string $secret, string $algorithm): bool;
}

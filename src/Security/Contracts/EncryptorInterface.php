<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Contracts;

use AINewsAutomator\Security\Secrets\EncryptedPayload;

/**
 * Authenticated encryption abstraction. Implementations MUST provide
 * confidentiality AND integrity (tamper detection) — a returned plaintext
 * is only produced if the ciphertext authenticates. The payload carries
 * version/algorithm/key-id metadata so crypto can be upgraded over time
 * and mixed-version stored data still decrypts.
 */
interface EncryptorInterface
{
    public function encrypt(string $plaintext): EncryptedPayload;

    /**
     * @throws \AINewsAutomator\Security\Exceptions\EncryptionException
     *         If the payload is malformed, uses an unknown algorithm/key,
     *         or fails authentication (tampering).
     */
    public function decrypt(EncryptedPayload $payload): string;
}

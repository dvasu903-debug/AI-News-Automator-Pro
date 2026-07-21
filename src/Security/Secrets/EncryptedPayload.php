<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Secrets;

use AINewsAutomator\Security\Exceptions\EncryptionException;

/**
 * A self-describing encrypted payload. Serializes to a compact JSON
 * envelope carrying the encryption version, algorithm identifier, key
 * identifier, nonce, and ciphertext. Storing this metadata alongside the
 * ciphertext is what makes future crypto upgrades safe: a decryptor reads
 * the envelope, sees which algorithm/key produced it, and dispatches
 * accordingly — so data encrypted under an old scheme still decrypts after
 * an upgrade, and re-encryption can be done incrementally.
 */
final class EncryptedPayload
{
    /**
     * @param int    $version    Encryption scheme version.
     * @param string $algorithm  Algorithm identifier, e.g. "xchacha20poly1305".
     * @param string $keyId      Identifies which key encrypted this (for rotation).
     * @param string $nonce      Raw nonce bytes.
     * @param string $ciphertext Raw ciphertext bytes (includes auth tag).
     */
    public function __construct(
        public readonly int $version,
        public readonly string $algorithm,
        public readonly string $keyId,
        public readonly string $nonce,
        public readonly string $ciphertext,
    ) {
    }

    /**
     * Serializes to a storable string. Binary fields are base64-encoded so
     * the result is safe for wp_options / database text columns.
     */
    public function toStorage(): string
    {
        $envelope = [
            'v'   => $this->version,
            'alg' => $this->algorithm,
            'kid' => $this->keyId,
            'n'   => base64_encode($this->nonce),
            'ct'  => base64_encode($this->ciphertext),
        ];

        $json = wp_json_encode($envelope);

        if ($json === false) {
            throw new EncryptionException('Failed to serialize encrypted payload.');
        }

        return $json;
    }

    /**
     * @throws EncryptionException On malformed input.
     */
    public static function fromStorage(string $stored): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($stored, true);

        if (!is_array($decoded)) {
            throw new EncryptionException('Malformed encrypted payload: not a JSON object.');
        }

        foreach (['v', 'alg', 'kid', 'n', 'ct'] as $required) {
            if (!array_key_exists($required, $decoded)) {
                throw new EncryptionException(sprintf('Malformed encrypted payload: missing "%s".', $required));
            }
        }

        $nonce = base64_decode((string) $decoded['n'], true);
        $ciphertext = base64_decode((string) $decoded['ct'], true);

        if ($nonce === false || $ciphertext === false) {
            throw new EncryptionException('Malformed encrypted payload: invalid base64.');
        }

        return new self(
            version: (int) $decoded['v'],
            algorithm: (string) $decoded['alg'],
            keyId: (string) $decoded['kid'],
            nonce: $nonce,
            ciphertext: $ciphertext,
        );
    }
}

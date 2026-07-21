<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Secrets;

use AINewsAutomator\Security\Contracts\EncryptorInterface;
use AINewsAutomator\Security\Exceptions\EncryptionException;

/**
 * Authenticated encryption via libsodium's secretbox (XSalsa20-Poly1305),
 * which ships in PHP 8.2 core — no external dependency. secretbox provides
 * confidentiality and integrity together: decrypt() only returns plaintext
 * if the ciphertext authenticates, so any tampering (even a single bit
 * flip) is detected and rejected rather than silently producing garbage.
 *
 * The algorithm identifier, encryption version, and key id are written into
 * the EncryptedPayload envelope so future upgrades (e.g. moving to
 * XChaCha20) can be introduced without breaking existing data: decrypt()
 * inspects the envelope and refuses payloads it can't handle rather than
 * misinterpreting them.
 */
final class SodiumEncryptor implements EncryptorInterface
{
    private const VERSION = 1;
    private const ALGORITHM = 'xsalsa20poly1305'; // secretbox

    public function __construct(private readonly KeyProvider $keyProvider)
    {
    }

    public function encrypt(string $plaintext): EncryptedPayload
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new EncryptionException('libsodium is not available in this PHP runtime.');
        }

        $keyId = $this->keyProvider->currentKeyId();
        $key = $this->keyProvider->keyFor($keyId);

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } catch (\SodiumException $e) {
            throw new EncryptionException('Encryption failed.', 0, $e);
        } finally {
            // Wipe the key copy from memory as soon as we're done with it —
            // but only where that's actually possible. sodium_memzero()
            // requires native libsodium; the sodium_compat polyfill (used
            // when PHP's ext-sodium isn't compiled in) implements every
            // other sodium_* function identically, but explicitly throws
            // on memzero() rather than pretend to wipe memory it has no
            // way to securely reach from pure PHP. extension_loaded('sodium')
            // is true only for the native extension — sodium_compat defines
            // its functions in userland, so it never registers as a loaded
            // extension, even though function_exists('sodium_crypto_secretbox')
            // stays true either way. On sodium_compat hosts the key copy is
            // simply left for normal PHP garbage collection instead — a real,
            // stated limitation, not a hidden one; encryption/decryption
            // themselves are unaffected.
            if (isset($key) && extension_loaded('sodium')) {
                sodium_memzero($key);
            }
        }

        return new EncryptedPayload(
            version: self::VERSION,
            algorithm: self::ALGORITHM,
            keyId: $keyId,
            nonce: $nonce,
            ciphertext: $ciphertext,
        );
    }

    public function decrypt(EncryptedPayload $payload): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new EncryptionException('libsodium is not available in this PHP runtime.');
        }

        if ($payload->algorithm !== self::ALGORITHM) {
            throw new EncryptionException(sprintf(
                'Unsupported algorithm "%s"; this encryptor handles "%s".',
                $payload->algorithm,
                self::ALGORITHM
            ));
        }

        if ($payload->version > self::VERSION) {
            throw new EncryptionException(sprintf(
                'Payload version %d is newer than this encryptor supports (%d).',
                $payload->version,
                self::VERSION
            ));
        }

        if (strlen($payload->nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new EncryptionException('Invalid nonce length.');
        }

        $key = $this->keyProvider->keyFor($payload->keyId);

        try {
            $plaintext = sodium_crypto_secretbox_open($payload->ciphertext, $payload->nonce, $key);
        } catch (\SodiumException $e) {
            throw new EncryptionException('Decryption failed.', 0, $e);
        } finally {
            // See encrypt()'s finally block for the full explanation —
            // sodium_memzero() only where native libsodium is actually
            // loaded; sodium_compat throws on it.
            if (isset($key) && extension_loaded('sodium')) {
                sodium_memzero($key);
            }
        }

        // secretbox_open returns false (not an exception) on authentication
        // failure — this is the tamper-detection path.
        if ($plaintext === false) {
            throw new EncryptionException('Decryption failed: authentication check did not pass (data may be tampered or the key changed).');
        }

        return $plaintext;
    }
}

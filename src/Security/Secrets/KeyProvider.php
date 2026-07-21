<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Secrets;

use AINewsAutomator\Security\Exceptions\EncryptionException;

/**
 * Derives symmetric encryption keys from WordPress's own secret salts.
 *
 * Design rationale: the key must NOT live in the database (or a DB breach
 * would expose both ciphertext and key). WordPress salts live in
 * wp-config.php / environment, so deriving from them keeps the key out of
 * the database. We run the salt through a KDF (HKDF via hash_hkdf) with a
 * fixed info-string, so the derived key is specific to this plugin and not
 * identical to WordPress's own use of the salt.
 *
 * Key versioning: each key has a string id ("v1", "v2", ...). The current
 * key id is what new encryptions use; older key ids remain derivable so
 * previously-encrypted data still decrypts. Rotation = introduce a new key
 * id, re-encrypt records, advance the current id. Because the derivation is
 * deterministic from (salt, keyId), we never store raw keys anywhere.
 *
 * Honest limitation (documented in the Threat Model): if the site owner
 * rotates their WordPress salts, the underlying secret changes and all
 * derived keys change with it — previously stored secrets become
 * undecryptable and must be re-entered. This is the accepted cost of not
 * storing a key in the database.
 */
final class KeyProvider
{
    private const KEY_BYTES = 32; // SODIUM_CRYPTO_SECRETBOX_KEYBYTES
    private const INFO_PREFIX = 'ai-news-automator:secretbox:';

    /**
     * @param string $currentKeyId The key id new encryptions use.
     */
    public function __construct(private readonly string $currentKeyId = 'v1')
    {
    }

    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    /**
     * Derives the 32-byte key for a given key id.
     *
     * @throws EncryptionException If the base secret is unavailable.
     */
    public function keyFor(string $keyId): string
    {
        $baseSecret = $this->baseSecret();

        if ($baseSecret === '') {
            throw new EncryptionException(
                'Cannot derive encryption key: WordPress secret salts are unavailable or empty.'
            );
        }

        // HKDF binds the derived key to both the plugin (info prefix) and the
        // specific key id, so different key ids yield independent keys.
        $key = hash_hkdf('sha256', $baseSecret, self::KEY_BYTES, self::INFO_PREFIX . $keyId);

        if (strlen($key) !== self::KEY_BYTES) {
            throw new EncryptionException('Derived key has unexpected length.');
        }

        return $key;
    }

    public function currentKey(): string
    {
        return $this->keyFor($this->currentKeyId);
    }

    /**
     * The base secret material. Uses WordPress's wp_salt('secure_auth') when
     * available (a stable, install-specific 64+ char secret). In a non-WP
     * context (unit tests) callers inject a deterministic secret via the
     * ANA_TEST_SALT constant so tests are reproducible.
     */
    private function baseSecret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('secure_auth');
        }

        if (defined('ANA_TEST_SALT')) {
            return (string) ANA_TEST_SALT;
        }

        return '';
    }
}

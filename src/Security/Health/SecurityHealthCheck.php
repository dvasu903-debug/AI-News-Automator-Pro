<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Health;

use AINewsAutomator\Security\Contracts\EncryptorInterface;
use AINewsAutomator\Security\Exceptions\EncryptionException;

/**
 * Runs the security self-tests, returning rich HealthCheckResult objects.
 * Generalizes the old plugin's flat pass/fail diagnostics into something
 * Monitoring can aggregate into a health score. Each check is independent
 * and never throws — a failing check produces a Critical/Warning result, it
 * doesn't abort the run.
 */
final class SecurityHealthCheck
{
    private const DOCS_BASE = 'https://example.com/ai-news-automator/docs/security#';

    public function __construct(private readonly EncryptorInterface $encryptor)
    {
    }

    /**
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->checkLibsodium(),
            $this->checkWordPressSalts(),
            $this->checkEncryptionRoundTrip(),
            $this->checkHttpsAdmin(),
        ];
    }

    private function checkLibsodium(): HealthCheckResult
    {
        if (function_exists('sodium_crypto_secretbox')) {
            return new HealthCheckResult(
                'libsodium available',
                HealthStatus::Ok,
                'libsodium is available; secrets can be encrypted at rest.'
            );
        }

        return new HealthCheckResult(
            'libsodium available',
            HealthStatus::Critical,
            'libsodium is not available in this PHP runtime.',
            'Upgrade to PHP 8.2+ (libsodium ships in core) or enable the sodium extension. Without it, API credentials cannot be encrypted.',
            false,
            self::DOCS_BASE . 'libsodium'
        );
    }

    private function checkWordPressSalts(): HealthCheckResult
    {
        // A default/placeholder salt means secrets are effectively unprotected.
        $salt = function_exists('wp_salt') ? (string) wp_salt('secure_auth') : '';

        if (strlen($salt) < 32 || str_contains($salt, 'put your unique phrase here')) {
            return new HealthCheckResult(
                'WordPress salts configured',
                HealthStatus::Critical,
                'WordPress secret salts appear to be missing or set to placeholder values.',
                'Define unique AUTH_KEY/SECURE_AUTH_KEY/etc. salts in wp-config.php. The encryption key is derived from these; placeholder salts make encryption ineffective.',
                false,
                self::DOCS_BASE . 'salts'
            );
        }

        return new HealthCheckResult(
            'WordPress salts configured',
            HealthStatus::Ok,
            'WordPress secret salts are configured.'
        );
    }

    private function checkEncryptionRoundTrip(): HealthCheckResult
    {
        try {
            $sample = 'health-check-' . bin2hex(random_bytes(8));
            $payload = $this->encryptor->encrypt($sample);
            $decrypted = $this->encryptor->decrypt($payload);

            if (!hash_equals($sample, $decrypted)) {
                return new HealthCheckResult(
                    'Encryption round-trip',
                    HealthStatus::Critical,
                    'Encryption round-trip produced mismatched output.',
                    'This indicates a crypto configuration problem. Stored secrets may be unreadable.',
                    false,
                    self::DOCS_BASE . 'encryption'
                );
            }

            return new HealthCheckResult(
                'Encryption round-trip',
                HealthStatus::Ok,
                'Encryption and decryption succeeded.'
            );
        } catch (EncryptionException $e) {
            return new HealthCheckResult(
                'Encryption round-trip',
                HealthStatus::Critical,
                'Encryption round-trip failed: ' . $e->getMessage(),
                'Verify libsodium and WordPress salts. Existing stored secrets may need to be re-entered.',
                false,
                self::DOCS_BASE . 'encryption'
            );
        }
    }

    private function checkHttpsAdmin(): HealthCheckResult
    {
        $adminOverHttps = function_exists('is_ssl') ? is_ssl() : false;

        if ($adminOverHttps) {
            return new HealthCheckResult(
                'Admin over HTTPS',
                HealthStatus::Ok,
                'The admin area is being served over HTTPS.'
            );
        }

        return new HealthCheckResult(
            'Admin over HTTPS',
            HealthStatus::Warning,
            'The admin area does not appear to be served over HTTPS.',
            'Serve wp-admin over HTTPS so nonces, credentials, and session cookies are not transmitted in cleartext.',
            false,
            self::DOCS_BASE . 'https'
        );
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Secrets;

use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Security\Contracts\EncryptorInterface;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Security\Exceptions\EncryptionException;

/**
 * Stores API credentials encrypted at rest and exposes them through Core's
 * SecretsProviderInterface, filling the seam declared back in Module 1.1.
 *
 * Each secret is a SecretRecord (encrypted value + metadata) stored in a
 * single wp_options map keyed by secret name. get() decrypts on demand and
 * touches lastUsedAt; getRecord() exposes metadata without decrypting.
 *
 * Storage note: Module 3 (Storage) may move this to a dedicated table, but
 * because callers only depend on SecretsProviderInterface, that change is a
 * container rebinding, not a caller change.
 */
final class CredentialVault implements SecretsProviderInterface
{
    private const OPTION_KEY = 'ai_news_automator_secrets';

    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly SecurityMetricsInterface $metrics,
    ) {
    }

    public function get(string $key): ?string
    {
        $record = $this->getRecord($key);

        if ($record === null) {
            return null;
        }

        try {
            $payload = EncryptedPayload::fromStorage($record->encryptedValue);
            $plaintext = $this->encryptor->decrypt($payload);
        } catch (EncryptionException $e) {
            // Never log the secret; log only that decryption failed and why.
            $this->logger->error('Failed to decrypt secret "{key}": {reason}', [
                'key'    => $key,
                'reason' => $e->getMessage(),
            ]);
            return null;
        }

        $this->metrics->increment('decrypt_operations');

        // Touch last-used metadata (best-effort; failure here must not block use).
        $this->persistRecord($key, $record->withLastUsedAt(time()));

        return $plaintext;
    }

    public function set(string $key, string $value): void
    {
        $this->setWithMetadata($key, $value);
    }

    /**
     * Stores a secret with optional lifecycle metadata.
     */
    public function setWithMetadata(
        string $key,
        string $value,
        ?string $provider = null,
        ?int $expiresAt = null
    ): void {
        $payload = $this->encryptor->encrypt($value);

        $existing = $this->getRecord($key);

        $record = new SecretRecord(
            encryptedValue: $payload->toStorage(),
            provider: $provider ?? $existing?->provider,
            expiresAt: $expiresAt ?? $existing?->expiresAt,
            lastValidatedAt: $existing?->lastValidatedAt,
            lastUsedAt: $existing?->lastUsedAt,
            createdAt: $existing?->createdAt ?: time(),
        );

        $this->persistRecord($key, $record);

        $this->logger->info('Secret "{key}" stored (provider: {provider}).', [
            'key'      => $key,
            'provider' => $provider ?? 'n/a',
        ]);
    }

    public function has(string $key): bool
    {
        return $this->getRecord($key) !== null;
    }

    public function forget(string $key): void
    {
        $all = $this->allRecords();
        unset($all[$key]);
        $this->persistAll($all);
    }

    public function getRecord(string $key): ?SecretRecord
    {
        $all = $this->allRecords();

        return $all[$key] ?? null;
    }

    /**
     * Marks a secret as successfully validated (e.g. after a test API call).
     */
    public function markValidated(string $key): void
    {
        $record = $this->getRecord($key);

        if ($record !== null) {
            $this->persistRecord($key, $record->withLastValidatedAt(time()));
        }
    }

    /**
     * @return array<string, SecretRecord>
     */
    private function allRecords(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        $records = [];

        foreach ($stored as $key => $data) {
            if (is_array($data)) {
                $records[(string) $key] = SecretRecord::fromArray($data);
            }
        }

        return $records;
    }

    private function persistRecord(string $key, SecretRecord $record): void
    {
        $all = $this->allRecords();
        $all[$key] = $record;
        $this->persistAll($all);
    }

    /**
     * @param array<string, SecretRecord> $records
     */
    private function persistAll(array $records): void
    {
        $serialized = [];

        foreach ($records as $key => $record) {
            $serialized[$key] = $record->toArray();
        }

        update_option(self::OPTION_KEY, $serialized, false);
    }
}

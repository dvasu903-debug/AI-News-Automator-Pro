<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Secrets;

/**
 * A stored secret plus its lifecycle metadata. The encrypted value is
 * kept as a serialized EncryptedPayload string; the metadata fields are
 * plaintext (they aren't sensitive) and power future dashboards:
 * expiry warnings, "last used" activity, provider attribution.
 */
final class SecretRecord
{
    /**
     * @param string      $encryptedValue Serialized EncryptedPayload.
     * @param string|null $provider       Owning provider, e.g. "anthropic".
     * @param int|null    $expiresAt      Unix timestamp, or null if non-expiring.
     * @param int|null    $lastValidatedAt Unix timestamp of last successful validation.
     * @param int|null    $lastUsedAt     Unix timestamp of last successful use.
     * @param int         $createdAt      Unix timestamp of creation.
     */
    public function __construct(
        public readonly string $encryptedValue,
        public readonly ?string $provider = null,
        public readonly ?int $expiresAt = null,
        public readonly ?int $lastValidatedAt = null,
        public readonly ?int $lastUsedAt = null,
        public readonly int $createdAt = 0,
    ) {
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /**
     * Returns a copy with lastUsedAt updated — records are immutable, so
     * "touching" a secret produces a new record the vault re-persists.
     */
    public function withLastUsedAt(int $timestamp): self
    {
        return new self(
            $this->encryptedValue,
            $this->provider,
            $this->expiresAt,
            $this->lastValidatedAt,
            $timestamp,
            $this->createdAt,
        );
    }

    public function withLastValidatedAt(int $timestamp): self
    {
        return new self(
            $this->encryptedValue,
            $this->provider,
            $this->expiresAt,
            $timestamp,
            $this->lastUsedAt,
            $this->createdAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'encrypted_value'   => $this->encryptedValue,
            'provider'          => $this->provider,
            'expires_at'        => $this->expiresAt,
            'last_validated_at' => $this->lastValidatedAt,
            'last_used_at'      => $this->lastUsedAt,
            'created_at'        => $this->createdAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            encryptedValue: (string) ($data['encrypted_value'] ?? ''),
            provider: isset($data['provider']) ? (string) $data['provider'] : null,
            expiresAt: isset($data['expires_at']) ? (int) $data['expires_at'] : null,
            lastValidatedAt: isset($data['last_validated_at']) ? (int) $data['last_validated_at'] : null,
            lastUsedAt: isset($data['last_used_at']) ? (int) $data['last_used_at'] : null,
            createdAt: (int) ($data['created_at'] ?? 0),
        );
    }
}

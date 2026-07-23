<?php
/**
 * PublishingProfile DTO.
 *
 * Immutable value object representing a publishing profile, matching
 * the real ana_publishing_profiles schema:
 *   Migration_20260722100001_CreatePublishingProfilesTable (frozen):
 *     id, slug, name, vertical, workflow_key, approval_mode, config,
 *     enabled, created_at, updated_at
 *   Migration_20260722100004_AddIsDefaultToPublishingProfilesTable
 *     (Milestone 2, this package): is_default
 *
 * Revision note (r3, Module 3 compatibility audit): the r2 draft of this
 * DTO was built against an assumed schema with post_type/is_active and
 * no vertical/workflow_key/approval_mode. This version replaces that
 * with the real column set. `postType()` has no replacement — the real
 * design ties a profile to a Module 7 workflow via `workflow_key`
 * (convention only, no DB foreign key — Storage's ADR-0004) plus a
 * `vertical` tag, not a WordPress post type.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\DTO;

use AINewsAutomator\Publishing\Exceptions\PublishingConfigurationException;
use DateTimeImmutable;

final class PublishingProfile
{
    /**
     * Convention-only version tag written into the `config` JSON payload.
     * Not part of the frozen schema (config is a free-form LONGTEXT
     * column) — kept as defensive practice so a future config shape
     * change can be migrated in the service layer without a DB migration.
     */
    public const CONFIG_SCHEMA_VERSION = 1;

    private const DEFAULT_VERTICAL = 'news';
    private const DEFAULT_APPROVAL_MODE = 'manual';

    private ?int $id;
    private string $slug;
    private string $name;
    private string $vertical;
    private string $workflowKey;
    private string $approvalMode;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    private bool $enabled;
    private bool $isDefault;
    private ?DateTimeImmutable $createdAt;
    private ?DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        ?int $id,
        string $slug,
        string $name,
        string $workflowKey,
        string $vertical = self::DEFAULT_VERTICAL,
        string $approvalMode = self::DEFAULT_APPROVAL_MODE,
        array $config = [],
        bool $enabled = true,
        bool $isDefault = false,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null
    ) {
        if (!isset($config['schema_version'])) {
            $config['schema_version'] = self::CONFIG_SCHEMA_VERSION;
        }

        $this->id           = $id;
        $this->slug         = $slug;
        $this->name         = $name;
        $this->vertical      = $vertical;
        $this->workflowKey  = $workflowKey;
        $this->approvalMode = $approvalMode;
        $this->config       = $config;
        $this->enabled      = $enabled;
        $this->isDefault    = $isDefault;
        $this->createdAt    = $createdAt;
        $this->updatedAt    = $updatedAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function vertical(): string
    {
        return $this->vertical;
    }

    public function workflowKey(): string
    {
        return $this->workflowKey;
    }

    public function approvalMode(): string
    {
        return $this->approvalMode;
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function configSchemaVersion(): int
    {
        return (int) ($this->config['schema_version'] ?? 0);
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public function configValue(string $path, $default = null)
    {
        $segments = explode('.', $path);
        $cursor   = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /*
    |--------------------------------------------------------------------------
    | Withers (immutability-preserving mutations)
    |--------------------------------------------------------------------------
    */

    public function withId(int $id): self
    {
        $clone     = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone       = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withSlug(string $slug): self
    {
        $clone       = clone $this;
        $clone->slug = $slug;

        return $clone;
    }

    public function withVertical(string $vertical): self
    {
        $clone           = clone $this;
        $clone->vertical = $vertical;

        return $clone;
    }

    public function withWorkflowKey(string $workflowKey): self
    {
        $clone              = clone $this;
        $clone->workflowKey = $workflowKey;

        return $clone;
    }

    public function withApprovalMode(string $approvalMode): self
    {
        $clone               = clone $this;
        $clone->approvalMode = $approvalMode;

        return $clone;
    }

    public function withEnabled(bool $enabled): self
    {
        $clone          = clone $this;
        $clone->enabled = $enabled;

        return $clone;
    }

    public function withDefault(bool $default): self
    {
        $clone            = clone $this;
        $clone->isDefault = $default;

        return $clone;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function withConfig(array $config): self
    {
        if (!isset($config['schema_version'])) {
            $config['schema_version'] = self::CONFIG_SCHEMA_VERSION;
        }

        $clone         = clone $this;
        $clone->config = $config;

        return $clone;
    }

    public function withTimestamps(
        ?DateTimeImmutable $createdAt,
        ?DateTimeImmutable $updatedAt
    ): self {
        $clone            = clone $this;
        $clone->createdAt = $createdAt;
        $clone->updatedAt = $updatedAt;

        return $clone;
    }

    /*
    |--------------------------------------------------------------------------
    | Serialization
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'slug'          => $this->slug,
            'name'          => $this->name,
            'vertical'      => $this->vertical,
            'workflow_key'  => $this->workflowKey,
            'approval_mode' => $this->approvalMode,
            'config'        => $this->config,
            'enabled'       => $this->enabled,
            'is_default'    => $this->isDefault,
            'created_at'    => $this->createdAt ? $this->createdAt->format(DATE_ATOM) : null,
            'updated_at'    => $this->updatedAt ? $this->updatedAt->format(DATE_ATOM) : null,
        ];
    }

    /**
     * @throws PublishingConfigurationException When encoding fails.
     */
    public function configJson(): string
    {
        $json = wp_json_encode($this->config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new PublishingConfigurationException(
                'Failed to encode publishing profile configuration: ' . json_last_error_msg()
            );
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws PublishingConfigurationException On malformed input.
     */
    public static function fromArray(array $data): self
    {
        foreach (['slug', 'name', 'workflow_key'] as $required) {
            if (!isset($data[$required]) || !is_string($data[$required])) {
                throw new PublishingConfigurationException(
                    sprintf('PublishingProfile::fromArray missing required string field "%s".', $required)
                );
            }
        }

        $config = $data['config'] ?? [];

        if (is_string($config)) {
            $decoded = json_decode($config, true);

            if (!is_array($decoded)) {
                throw new PublishingConfigurationException(
                    'PublishingProfile config JSON is malformed: ' . json_last_error_msg()
                );
            }

            $config = $decoded;
        }

        if (!is_array($config)) {
            throw new PublishingConfigurationException(
                'PublishingProfile config must be an array or JSON string.'
            );
        }

        return new self(
            isset($data['id']) ? (int) $data['id'] : null,
            $data['slug'],
            $data['name'],
            $data['workflow_key'],
            isset($data['vertical']) ? (string) $data['vertical'] : self::DEFAULT_VERTICAL,
            isset($data['approval_mode']) ? (string) $data['approval_mode'] : self::DEFAULT_APPROVAL_MODE,
            $config,
            (bool) ($data['enabled'] ?? true),
            (bool) ($data['is_default'] ?? false),
            self::parseTimestamp($data['created_at'] ?? null),
            self::parseTimestamp($data['updated_at'] ?? null)
        );
    }

    /**
     * @param mixed $value
     */
    private static function parseTimestamp($value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $value)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value);

        if (false === $parsed) {
            throw new PublishingConfigurationException(
                sprintf('Unparseable timestamp "%s" in PublishingProfile data.', (string) $value)
            );
        }

        return $parsed;
    }
}

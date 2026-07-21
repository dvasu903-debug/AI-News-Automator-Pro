<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Prompt;

/**
 * A durable, semantically-versioned prompt template. Versions are
 * immutable by convention enforced at the repository layer
 * (PromptTemplateRepository::saveNewVersion() refuses to overwrite an
 * existing (name, version) pair) — this value object itself is just data.
 */
final class PromptTemplate
{
    /**
     * @param array<string, mixed> $variablesSchema
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $vertical,
        public readonly string $templateText,
        public readonly array $variablesSchema,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: (string) $row['name'],
            version: (string) $row['version'],
            vertical: (string) $row['vertical'],
            templateText: (string) $row['template_text'],
            variablesSchema: is_string($row['variables_schema'] ?? null) ? (json_decode($row['variables_schema'], true) ?: []) : [],
            createdAt: \AINewsAutomator\Storage\Entities\EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'name'             => $this->name,
            'version'          => $this->version,
            'vertical'         => $this->vertical,
            'template_text'    => $this->templateText,
            'variables_schema' => wp_json_encode($this->variablesSchema) ?: '{}',
            'created_at'       => \AINewsAutomator\Storage\Entities\EntityDates::toMysql($this->createdAt),
        ];
    }

    public function isValidSemver(): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $this->version) === 1;
    }
}

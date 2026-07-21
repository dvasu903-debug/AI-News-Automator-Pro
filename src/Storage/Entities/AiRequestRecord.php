<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * A row in `ana_ai_requests` — the cost/usage ledger for one AI provider
 * call. Written by whichever module calls an AI provider (Module 4+).
 */
final class AiRequestRecord
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $purpose,
        public readonly ?string $correlationId,
        public readonly ?int $promptTokens,
        public readonly ?int $completionTokens,
        public readonly ?int $costCents,
        public readonly string $status,
        public readonly ?string $error,
        public readonly ?int $durationMs,
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
            provider: (string) $row['provider'],
            model: (string) $row['model'],
            purpose: (string) $row['purpose'],
            correlationId: $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
            promptTokens: isset($row['prompt_tokens']) ? (int) $row['prompt_tokens'] : null,
            completionTokens: isset($row['completion_tokens']) ? (int) $row['completion_tokens'] : null,
            costCents: isset($row['cost_cents']) ? (int) $row['cost_cents'] : null,
            status: (string) $row['status'],
            error: $row['error'] !== null ? (string) $row['error'] : null,
            durationMs: isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            createdAt: EntityDates::fromMysql((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'provider'          => $this->provider,
            'model'             => $this->model,
            'purpose'           => $this->purpose,
            'correlation_id'    => $this->correlationId,
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cost_cents'        => $this->costCents,
            'status'            => $this->status,
            'error'             => $this->error,
            'duration_ms'       => $this->durationMs,
            'created_at'        => EntityDates::toMysql($this->createdAt),
        ];
    }
}

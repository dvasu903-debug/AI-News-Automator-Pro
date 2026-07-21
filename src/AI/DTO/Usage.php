<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * Token/cost accounting for one request — maps directly onto Storage's
 * AiRequestRecord fields (prompt_tokens, completion_tokens, cost_cents),
 * so AIManager's recording step is a near-direct field copy, not a
 * translation.
 */
final class Usage
{
    public function __construct(
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly ?int $costCents = null,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function withCostCents(int $costCents): self
    {
        return new self($this->promptTokens, $this->completionTokens, $costCents);
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * A provider-agnostic chat request. Every ChatProviderInterface adapter
 * translates this into its own vendor's wire format. Fields that only
 * some providers support (tools, responseSchema, promptCaching) are
 * simply ignored by adapters that don't understand them — AIManager is
 * responsible for checking capability BEFORE setting a field a resolved
 * provider+model can't honor (see AIRequestValidator).
 */
final class ChatRequest
{
    /**
     * @param list<Message> $messages
     * @param list<ToolDefinition> $tools
     * @param array<string, mixed>|null $responseSchema JSON Schema for structured output.
     */
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 1.0,
        public readonly array $tools = [],
        public readonly ?array $responseSchema = null,
        public readonly bool $promptCaching = false,
        public readonly ?string $correlationId = null,
    ) {
    }

    public function withModel(string $model): self
    {
        return new self(
            $this->messages,
            $model,
            $this->maxTokens,
            $this->temperature,
            $this->tools,
            $this->responseSchema,
            $this->promptCaching,
            $this->correlationId
        );
    }

    public function requiresVision(): bool
    {
        foreach ($this->messages as $message) {
            foreach ($message->content as $part) {
                if ($part->type === ContentPartType::Image) {
                    return true;
                }
            }
        }

        return false;
    }

    public function requiresToolCalling(): bool
    {
        return $this->tools !== [];
    }

    public function requiresStructuredOutput(): bool
    {
        return $this->responseSchema !== null;
    }

    /**
     * A stable cache key: everything that affects the response, and
     * nothing that doesn't (no correlation id — two identical prompts
     * with different correlation ids should still cache-hit each other).
     */
    public function cacheKey(): string
    {
        $payload = [
            'model'       => $this->model,
            'maxTokens'   => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages'    => array_map(
                static fn (Message $m): array => [
                    'role' => $m->role->value,
                    'text' => $m->text(),
                ],
                $this->messages
            ),
            'responseSchema' => $this->responseSchema,
        ];

        return 'chat:' . md5((string) wp_json_encode($payload));
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Providers;

use AINewsAutomator\AI\Contracts\ChatProviderInterface;
use AINewsAutomator\AI\Contracts\StreamingProviderInterface;
use AINewsAutomator\AI\Contracts\StructuredOutputProviderInterface;
use AINewsAutomator\AI\Contracts\ToolCallingProviderInterface;
use AINewsAutomator\AI\Contracts\VisionProviderInterface;
use AINewsAutomator\AI\DTO\ChatChunk;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\ChatResponse;
use AINewsAutomator\AI\DTO\ContentPart;
use AINewsAutomator\AI\DTO\ContentPartType;
use AINewsAutomator\AI\DTO\MessageRole;
use AINewsAutomator\AI\DTO\ProviderCapabilities;
use AINewsAutomator\AI\DTO\ProviderHealth;
use AINewsAutomator\AI\DTO\StopReason;
use AINewsAutomator\AI\DTO\ToolCall;
use AINewsAutomator\AI\DTO\Usage;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;

/**
 * Dedicated adapter for Anthropic's Messages API — kept separate from
 * OpenAiCompatibleProvider because Claude's request/response shape
 * (content-block arrays, system prompt as a top-level field rather than
 * a message role, `input_schema` for tools) is genuinely structurally
 * different, not just a different base URL (see design doc §2.4). No
 * native image generation, embeddings, or speech — this class
 * deliberately implements only the interfaces Claude genuinely supports.
 */
final class ClaudeProvider extends AbstractHttpProvider implements
    ChatProviderInterface,
    StreamingProviderInterface,
    VisionProviderInterface,
    ToolCallingProviderInterface,
    StructuredOutputProviderInterface
{
    private const ID = 'claude';
    private const BASE_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const SECRET_KEY = 'ai.claude.api_key';

    public function __construct(
        OutboundHttpValidator $http,
        LoggerInterface $logger,
        private readonly SecretsProviderInterface $secrets,
    ) {
        parent::__construct($http, $logger);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'Anthropic Claude';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chat: true,
            streaming: true,
            vision: true,
            toolCalling: true,
            structuredOutput: true,
            imageGeneration: false,
            embeddings: false,
            speech: false,
        );
    }

    public function healthCheck(): ProviderHealth
    {
        return $this->buildHealthCheck(self::ID);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $body = $this->buildRequestBody($request, stream: false);
        $decoded = $this->post(self::ID, self::BASE_URL, $this->headers(), $body, 30);

        return $this->parseResponse($decoded, $request->model);
    }

    public function streamChat(ChatRequest $request): iterable
    {
        // Same non-incremental-but-correct posture as OpenAiCompatibleProvider
        // — see that class's streamChat() docblock for the full rationale.
        $response = $this->chat($request);

        yield new ChatChunk(
            delta: $response->content,
            isFinal: true,
            usage: $response->usage,
            stopReason: $response->stopReason
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestBody(ChatRequest $request, bool $stream): array
    {
        $systemText = '';
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === MessageRole::System) {
                $systemText .= ($systemText !== '' ? "\n" : '') . $message->text();
                continue;
            }

            $messages[] = [
                'role'    => $message->role === MessageRole::Assistant ? 'assistant' : 'user',
                'content' => array_map(static function (ContentPart $part): array {
                    if ($part->type === ContentPartType::Text) {
                        return ['type' => 'text', 'text' => $part->text];
                    }

                    return $part->imageBase64 !== null
                        ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $part->imageMediaType, 'data' => $part->imageBase64]]
                        : ['type' => 'image', 'source' => ['type' => 'url', 'url' => $part->imageUrl]];
                }, $message->content),
            ];
        }

        $body = [
            'model'      => $request->model,
            'max_tokens' => $request->maxTokens,
            'messages'   => $messages,
            'stream'     => $stream,
        ];

        if ($systemText !== '') {
            $body['system'] = $systemText;
        }

        if ($request->tools !== []) {
            $body['tools'] = array_map(static fn ($tool): array => [
                'name'         => $tool->name,
                'description'  => $tool->description,
                'input_schema' => $tool->parametersSchema,
            ], $request->tools);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function parseResponse(array $decoded, string $model): ChatResponse
    {
        $text = '';
        $toolCalls = [];

        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($block['id'] ?? ''),
                    toolName: (string) ($block['name'] ?? ''),
                    arguments: is_array($block['input'] ?? null) ? $block['input'] : []
                );
            }
        }

        $usage = new Usage(
            promptTokens: (int) ($decoded['usage']['input_tokens'] ?? 0),
            completionTokens: (int) ($decoded['usage']['output_tokens'] ?? 0)
        );

        return new ChatResponse(
            content: $text,
            toolCalls: $toolCalls,
            usage: $usage,
            stopReason: $this->mapStopReason((string) ($decoded['stop_reason'] ?? '')),
            providerId: self::ID,
            model: $model,
            raw: $decoded
        );
    }

    private function mapStopReason(string $stopReason): StopReason
    {
        return match ($stopReason) {
            'end_turn', 'stop_sequence' => StopReason::EndTurn,
            'max_tokens'                => StopReason::MaxTokens,
            'tool_use'                  => StopReason::ToolUse,
            default                      => StopReason::Unknown,
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'content-type'      => 'application/json',
            'anthropic-version' => self::API_VERSION,
        ];

        $apiKey = $this->secrets->get(self::SECRET_KEY);
        if ($apiKey !== null) {
            $headers['x-api-key'] = $apiKey;
        }

        return $headers;
    }
}

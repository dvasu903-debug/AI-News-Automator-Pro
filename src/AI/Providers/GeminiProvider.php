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
 * Dedicated adapter for Google's Gemini `generateContent` API — kept
 * separate from OpenAiCompatibleProvider because Gemini's shape (`parts`
 * within `contents`, `functionDeclarations`, `x-goog-api-key` header
 * rather than Bearer auth) genuinely differs, not just the base URL.
 */
final class GeminiProvider extends AbstractHttpProvider implements
    ChatProviderInterface,
    StreamingProviderInterface,
    VisionProviderInterface,
    ToolCallingProviderInterface,
    StructuredOutputProviderInterface
{
    private const ID = 'gemini';
    private const SECRET_KEY = 'ai.gemini.api_key';

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
        return 'Google Gemini';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chat: true,
            streaming: true,
            vision: true,
            toolCalling: true,
            structuredOutput: true,
            imageGeneration: false, // Image generation (Nano Banana/Imagen) is a distinct
            embeddings: false,      // API surface from generateContent; not implemented in
            speech: false,          // this module's ChatProviderInterface path yet — see
        );                          // module README extension guide for adding ImageProviderInterface here.
    }

    public function healthCheck(): ProviderHealth
    {
        return $this->buildHealthCheck(self::ID);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $body = $this->buildRequestBody($request);
        $decoded = $this->post(self::ID, $this->url($request->model), $this->headers(), $body, 30);

        return $this->parseResponse($decoded, $request->model);
    }

    public function streamChat(ChatRequest $request): iterable
    {
        // Same non-incremental-but-correct posture as the other adapters —
        // see OpenAiCompatibleProvider::streamChat() for the full rationale.
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
    private function buildRequestBody(ChatRequest $request): array
    {
        $systemText = '';
        $contents = [];

        foreach ($request->messages as $message) {
            if ($message->role === MessageRole::System) {
                $systemText .= ($systemText !== '' ? "\n" : '') . $message->text();
                continue;
            }

            $contents[] = [
                'role'  => $message->role === MessageRole::Assistant ? 'model' : 'user',
                'parts' => array_map(static function (ContentPart $part): array {
                    if ($part->type === ContentPartType::Text) {
                        return ['text' => $part->text];
                    }

                    return $part->imageBase64 !== null
                        ? ['inline_data' => ['mime_type' => $part->imageMediaType, 'data' => $part->imageBase64]]
                        : ['file_data' => ['file_uri' => $part->imageUrl]];
                }, $message->content),
            ];
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $request->maxTokens,
                'temperature'     => $request->temperature,
            ],
        ];

        if ($systemText !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $systemText]]];
        }

        if ($request->tools !== []) {
            $body['tools'] = [[
                'functionDeclarations' => array_map(static fn ($tool): array => [
                    'name'        => $tool->name,
                    'description' => $tool->description,
                    'parameters'  => $tool->parametersSchema,
                ], $request->tools),
            ]];
        }

        if ($request->responseSchema !== null) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $request->responseSchema;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function parseResponse(array $decoded, string $model): ChatResponse
    {
        $candidate = $decoded['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $text = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            } elseif (isset($part['functionCall'])) {
                $toolCalls[] = new ToolCall(
                    id: (string) ($part['functionCall']['name'] ?? '') . '_' . count($toolCalls),
                    toolName: (string) ($part['functionCall']['name'] ?? ''),
                    arguments: is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : []
                );
            }
        }

        $usage = new Usage(
            promptTokens: (int) ($decoded['usageMetadata']['promptTokenCount'] ?? 0),
            completionTokens: (int) ($decoded['usageMetadata']['candidatesTokenCount'] ?? 0)
        );

        return new ChatResponse(
            content: $text,
            toolCalls: $toolCalls,
            usage: $usage,
            stopReason: $this->mapStopReason((string) ($candidate['finishReason'] ?? '')),
            providerId: self::ID,
            model: $model,
            raw: $decoded
        );
    }

    private function mapStopReason(string $finishReason): StopReason
    {
        return match ($finishReason) {
            'STOP'         => StopReason::EndTurn,
            'MAX_TOKENS'   => StopReason::MaxTokens,
            'SAFETY'       => StopReason::ContentFilter,
            default         => StopReason::Unknown,
        };
    }

    private function url(string $model): string
    {
        return sprintf('https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', $model);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['content-type' => 'application/json'];

        $apiKey = $this->secrets->get(self::SECRET_KEY);
        if ($apiKey !== null) {
            $headers['x-goog-api-key'] = $apiKey;
        }

        return $headers;
    }
}

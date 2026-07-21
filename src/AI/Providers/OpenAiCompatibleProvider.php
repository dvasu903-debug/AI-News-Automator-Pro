<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Providers;

use AINewsAutomator\AI\Config\ProviderConfig;
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
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\DTO\ProviderCapabilities;
use AINewsAutomator\AI\DTO\ProviderHealth;
use AINewsAutomator\AI\DTO\StopReason;
use AINewsAutomator\AI\DTO\ToolCall;
use AINewsAutomator\AI\DTO\Usage;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;

/**
 * ONE class serving every OpenAI-compatible vendor: OpenAI itself,
 * OpenRouter, DeepSeek, Grok (xAI), and Ollama's OpenAI-compatible
 * endpoint — confirmed directly against each vendor's current docs
 * (see design doc §2.4/Part 4). Adding another OpenAI-compatible vendor
 * means adding a ProviderConfig entry in AIServiceProvider, never a new
 * class — this is the concrete realization of "adding a new AI provider
 * later should mostly be a matter of configuration."
 *
 * A ProviderConfig with e.g. supportsVision=false means capabilities()
 * reports that honestly, and AIRequestValidator refuses a vision request
 * before this class ever builds a request body containing an image part.
 */
final class OpenAiCompatibleProvider extends AbstractHttpProvider implements
    ChatProviderInterface,
    StreamingProviderInterface,
    VisionProviderInterface,
    ToolCallingProviderInterface,
    StructuredOutputProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $providerConfig,
        OutboundHttpValidator $http,
        LoggerInterface $logger,
        private readonly SecretsProviderInterface $secrets,
    ) {
        parent::__construct($http, $logger);
    }

    public function id(): string
    {
        return $this->providerConfig->id;
    }

    public function displayName(): string
    {
        return $this->providerConfig->displayName;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chat: true,
            streaming: $this->providerConfig->supportsStreaming,
            vision: $this->providerConfig->supportsVision,
            toolCalling: $this->providerConfig->supportsToolCalling,
            structuredOutput: $this->providerConfig->supportsStructuredOutput,
        );
    }

    public function healthCheck(): ProviderHealth
    {
        return $this->buildHealthCheck($this->id());
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $body = $this->buildRequestBody($request, stream: false);
        $decoded = $this->post($this->id(), $this->url(), $this->headers(), $body, $this->providerConfig->timeoutSeconds);

        return $this->parseResponse($decoded, $request->model);
    }

    public function streamChat(ChatRequest $request): iterable
    {
        // Server-sent-event streaming requires reading the HTTP response
        // body incrementally, which wp_remote_post() (used by
        // OutboundHttpValidator) does not support — it returns a complete
        // response. A genuinely incremental client is lower-level infra
        // (curl_multi with a write callback, still routed through
        // UrlGuard first) that belongs with Module 7's async execution
        // work, not duplicated here. This method is a CORRECT, if
        // non-incremental, implementation now: it performs the full
        // request and yields the complete response as one final chunk,
        // so code written against the streaming interface today keeps
        // working unchanged once true incremental delivery lands.
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
        $messages = array_map(function (Message $message): array {
            if (count($message->content) === 1 && $message->content[0]->type === ContentPartType::Text) {
                return ['role' => $message->role->value, 'content' => $message->content[0]->text];
            }

            $parts = array_map(static function (ContentPart $part): array {
                return $part->type === ContentPartType::Text
                    ? ['type' => 'text', 'text' => $part->text]
                    : ['type' => 'image_url', 'image_url' => ['url' => $part->imageUrl ?? ('data:' . $part->imageMediaType . ';base64,' . $part->imageBase64)]];
            }, $message->content);

            return ['role' => $message->role->value, 'content' => $parts];
        }, $request->messages);

        $body = [
            'model'       => $request->model,
            'messages'    => $messages,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
            'stream'      => $stream,
        ];

        if ($request->tools !== [] && $this->providerConfig->supportsToolCalling) {
            $body['tools'] = array_map(static fn ($tool): array => [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool->name,
                    'description' => $tool->description,
                    'parameters'  => $tool->parametersSchema,
                ],
            ], $request->tools);
        }

        if ($request->responseSchema !== null && $this->providerConfig->supportsStructuredOutput) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => ['name' => 'response', 'schema' => $request->responseSchema, 'strict' => true],
            ];
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function parseResponse(array $decoded, string $model): ChatResponse
    {
        $choice = $decoded['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $toolCalls[] = new ToolCall(
                id: (string) ($call['id'] ?? ''),
                toolName: (string) ($call['function']['name'] ?? ''),
                arguments: is_array($arguments) ? $arguments : []
            );
        }

        $usage = new Usage(
            promptTokens: (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($decoded['usage']['completion_tokens'] ?? 0)
        );

        return new ChatResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            usage: $usage,
            stopReason: $this->mapStopReason((string) ($choice['finish_reason'] ?? '')),
            providerId: $this->id(),
            model: $model,
            raw: $decoded
        );
    }

    private function mapStopReason(string $finishReason): StopReason
    {
        return match ($finishReason) {
            'stop'           => StopReason::EndTurn,
            'length'         => StopReason::MaxTokens,
            'tool_calls'     => StopReason::ToolUse,
            'content_filter' => StopReason::ContentFilter,
            default          => StopReason::Unknown,
        };
    }

    private function url(): string
    {
        return rtrim($this->providerConfig->baseUrl, '/') . $this->providerConfig->chatEndpoint;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['content-type' => 'application/json'];

        $secretKey = $this->providerConfig->secretKey;
        if ($secretKey !== null) {
            $apiKey = $this->secrets->get($secretKey);
            if ($apiKey !== null) {
                $headers[$this->providerConfig->authHeaderName] = sprintf($this->providerConfig->authHeaderFormat, $apiKey);
            }
        }

        return $headers;
    }
}

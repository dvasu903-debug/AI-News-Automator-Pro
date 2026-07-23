<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI\Fakes;

use AINewsAutomator\AI\Contracts\ChatProviderInterface;
use AINewsAutomator\AI\Contracts\StreamingProviderInterface;
use AINewsAutomator\AI\Contracts\StructuredOutputProviderInterface;
use AINewsAutomator\AI\Contracts\ToolCallingProviderInterface;
use AINewsAutomator\AI\Contracts\VisionProviderInterface;
use AINewsAutomator\AI\DTO\ChatChunk;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\ChatResponse;
use AINewsAutomator\AI\DTO\ProviderCapabilities;
use AINewsAutomator\AI\DTO\ProviderHealth;
use AINewsAutomator\AI\DTO\ProviderHealthStatus;
use AINewsAutomator\AI\DTO\StopReason;
use AINewsAutomator\AI\DTO\Usage;

/**
 * A fully-scriptable fake provider: queue up responses and/or exceptions
 * to be returned on successive chat() calls, in order. The primary tool
 * for testing AIManager's orchestration (retry, failover, caching, event
 * dispatch) without any real HTTP call — mirrors FakeWpdb's role in
 * Module 3's tests.
 */
final class FakeChatProvider implements
    ChatProviderInterface,
    StreamingProviderInterface,
    VisionProviderInterface,
    ToolCallingProviderInterface,
    StructuredOutputProviderInterface
{
    /** @var list<\Throwable|ChatResponse> */
    private array $queue = [];

    public int $callCount = 0;

    private ProviderHealthStatus $health = ProviderHealthStatus::Healthy;

    public function __construct(private readonly string $providerId = 'fake')
    {
    }

    public function id(): string
    {
        return $this->providerId;
    }

    public function displayName(): string
    {
        return 'Fake Provider (' . $this->providerId . ')';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chat: true,
            streaming: true,
            vision: true,
            toolCalling: true,
            structuredOutput: true,
        );
    }

    public function healthCheck(): ProviderHealth
    {
        return new ProviderHealth($this->providerId, $this->health);
    }

    public function setHealth(ProviderHealthStatus $status): void
    {
        $this->health = $status;
    }

    public function willReturn(ChatResponse $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function willThrow(\Throwable $exception): self
    {
        $this->queue[] = $exception;
        return $this;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->callCount++;

        if ($this->queue === []) {
            return $this->defaultResponse($request->model);
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function streamChat(ChatRequest $request): iterable
    {
        $response = $this->chat($request);

        yield new ChatChunk($response->content, isFinal: true, usage: $response->usage, stopReason: $response->stopReason);
    }

    public static function successResponse(string $providerId = 'fake', string $content = 'ok', int $promptTokens = 10, int $completionTokens = 5): ChatResponse
    {
        return new ChatResponse(
            content: $content,
            toolCalls: [],
            usage: new Usage($promptTokens, $completionTokens),
            stopReason: StopReason::EndTurn,
            providerId: $providerId,
            model: 'fake-model'
        );
    }

    private function defaultResponse(string $model): ChatResponse
    {
        return new ChatResponse(
            content: 'default response',
            toolCalls: [],
            usage: new Usage(10, 5),
            stopReason: StopReason::EndTurn,
            providerId: $this->providerId,
            model: $model
        );
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Manager;

use AINewsAutomator\AI\Contracts\AIRequestValidatorInterface;
use AINewsAutomator\AI\Contracts\ChatProviderInterface;
use AINewsAutomator\AI\Contracts\CostCalculatorInterface;
use AINewsAutomator\AI\Contracts\FailoverPolicyInterface;
use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\AI\Contracts\ResponseCacheInterface;
use AINewsAutomator\AI\Contracts\StreamingProviderInterface;
use AINewsAutomator\AI\DTO\ChatChunk;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\ChatResponse;
use AINewsAutomator\AI\Events\AIFailoverTriggeredEvent;
use AINewsAutomator\AI\Events\AIProviderUnavailableEvent;
use AINewsAutomator\AI\Events\AIRequestCompletedEvent;
use AINewsAutomator\AI\Events\AIRequestFailedEvent;
use AINewsAutomator\AI\Events\AIRequestStartedEvent;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\AI\Exceptions\ProviderUnavailableException;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Storage\Contracts\AiRequestRepositoryInterface;
use AINewsAutomator\Storage\Contracts\MetricsRepositoryInterface;
use AINewsAutomator\Storage\Entities\AiRequestRecord;
use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * The single entry point every future module depends on for AI work —
 * never a concrete provider, never AIProviderInterface directly. Mirrors
 * the mental model: AIManager orchestrates, ProviderRegistry discovers,
 * provider adapters translate, Storage persists, Security protects.
 *
 * chat() is the primary, non-streaming path (the engine's real current
 * consumers are background queue jobs with no observer for incremental
 * tokens). streamChat() is a distinct, explicitly opt-in method — see
 * the module README for the full streaming rationale.
 */
final class AIManager
{
    public function __construct(
        private readonly ProviderRegistryInterface $registry,
        private readonly AIRequestValidatorInterface $validator,
        private readonly ResponseCacheInterface $cache,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly RetryExecutor $retryExecutor,
        private readonly FailoverPolicyInterface $failover,
        private readonly CostCalculatorInterface $costCalculator,
        private readonly AiRequestRepositoryInterface $requestRepository,
        private readonly MetricsRepositoryInterface $metrics,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly CorrelationContext $correlation,
    ) {
    }

    public function chat(ChatRequest $request, ?string $providerId = null): ChatResponse
    {
        $provider = $this->resolveProvider(ChatProviderInterface::class, $providerId, 'chat');

        $this->validator->validateChatRequest($request, $provider);

        $cacheKey = $request->cacheKey();
        $cached = $this->cache->get($cacheKey);

        if ($cached instanceof ChatResponse) {
            $this->metrics->increment('ai.cache_hits');
            return $cached->withFromCache(true);
        }

        $this->metrics->increment('ai.cache_misses');

        return $this->executeWithFailover(
            capability: 'chat',
            initialProvider: $provider,
            attempt: function ($currentProvider) use ($request): ChatResponse {
                /** @var ChatProviderInterface $currentProvider */
                return $currentProvider->chat($request);
            },
            onSuccess: function (ChatResponse $response, float $latencyMs) use ($cacheKey, $request): void {
                $this->cache->set($cacheKey, $response, 3600);
                $this->recordSuccess($request->correlationId, $response, $latencyMs, 'chat');
            },
            onFailure: function (AIException $e, string $providerId) use ($request): void {
                $this->recordFailure($request->correlationId, $providerId, $request->model, $e);
            },
            correlationId: $request->correlationId,
            model: $request->model,
        );
    }

    /**
     * @return iterable<ChatChunk>
     */
    public function streamChat(ChatRequest $request, ?string $providerId = null): iterable
    {
        $provider = $this->resolveProvider(StreamingProviderInterface::class, $providerId, 'chat');
        $this->validator->validateChatRequest($request, $provider);

        $key = $this->rateLimitKeyFor($provider->id());
        $rateResult = $this->rateLimiter->hit($key, 30, 60);

        if (!$rateResult->allowed) {
            $this->metrics->increment('ai.rate_limited');
            throw new AIException(
                sprintf('Rate limit exceeded for provider "%s".', $provider->id()),
                \AINewsAutomator\AI\Exceptions\AIErrorType::RateLimited,
                $provider->id()
            );
        }

        $this->events->dispatch(new AIRequestStartedEvent(
            $this->metadataFactory->create('AI', ['provider' => $provider->id()]),
            providerId: $provider->id(),
            model: $request->model,
            capability: 'chat.stream',
        ));

        /** @var StreamingProviderInterface $provider */
        return $provider->streamChat($request);
    }

    /**
     * @param class-string $capabilityInterface
     */
    private function resolveProvider(string $capabilityInterface, ?string $providerId, string $capabilityKey): object
    {
        $provider = $providerId !== null
            ? $this->registry->get($providerId)
            : $this->registry->defaultFor($capabilityKey);

        if ($provider === null || !$provider instanceof $capabilityInterface) {
            throw new AIException(
                sprintf('No registered provider satisfies "%s" for capability "%s".', $capabilityInterface, $capabilityKey),
                \AINewsAutomator\AI\Exceptions\AIErrorType::Unknown
            );
        }

        return $provider;
    }

    /**
     * The shared retry+failover execution shell for any capability. Kept
     * generic (callable-based) so chat() doesn't duplicate this
     * orchestration logic — future capabilities (image, embedding) reuse
     * the exact same shell.
     *
     * @template T
     * @param callable(object): T $attempt
     * @param callable(T, float): void $onSuccess
     * @param callable(AIException, string): void $onFailure
     */
    private function executeWithFailover(
        string $capability,
        object $initialProvider,
        callable $attempt,
        callable $onSuccess,
        callable $onFailure,
        ?string $correlationId,
        string $model,
    ): mixed {
        $tried = [];
        $currentProvider = $initialProvider;

        while (true) {
            $providerId = $currentProvider->id();
            $tried[] = $providerId;

            $key = $this->rateLimitKeyFor($providerId);
            $rateResult = $this->rateLimiter->hit($key, 30, 60);

            if (!$rateResult->allowed) {
                $this->metrics->increment('ai.rate_limited');
                $onFailure(new AIException(
                    sprintf('Rate limit exceeded for provider "%s".', $providerId),
                    \AINewsAutomator\AI\Exceptions\AIErrorType::RateLimited,
                    $providerId
                ), $providerId);

                $next = $this->attemptFailover($capability, $providerId, $tried);
                if ($next === null) {
                    throw ProviderUnavailableException::afterRetries($providerId, 1, new AIException('Rate limited and no failover available.'));
                }
                $currentProvider = $next;
                continue;
            }

            $this->events->dispatch(new AIRequestStartedEvent(
                $this->metadataFactory->create('AI', ['provider' => $providerId]),
                providerId: $providerId,
                model: $model,
                capability: $capability,
            ));

            $start = microtime(true);

            try {
                $result = $this->retryExecutor->execute($providerId, fn () => $attempt($currentProvider));
                $latencyMs = (microtime(true) - $start) * 1000;
                $this->metrics->record('ai.latency_ms', (int) $latencyMs, ['provider' => $providerId]);
                $onSuccess($result, $latencyMs);
                return $result;
            } catch (ProviderUnavailableException $e) {
                $this->metrics->increment('ai.retries');
                $onFailure($e, $providerId);

                $this->events->dispatch(new AIProviderUnavailableEvent(
                    $this->metadataFactory->create('AI', ['provider' => $providerId]),
                    providerId: $providerId,
                    attempts: 1,
                    detail: $e->getMessage(),
                ));

                $next = $this->attemptFailover($capability, $providerId, $tried);

                if ($next === null) {
                    throw $e;
                }

                $currentProvider = $next;
                continue;
            } catch (AIException $e) {
                // Non-retryable (validation, auth, quota, unsupported
                // capability) — do not fail over, the SAME problem would
                // recur against any provider for auth/quota, and failing
                // over a validation error would just mask a real bug.
                $onFailure($e, $providerId);
                throw $e;
            }
        }
    }

    private function attemptFailover(string $capability, string $fromProviderId, array $excluded): ?object
    {
        $capabilityInterface = match ($capability) {
            'chat' => ChatProviderInterface::class,
            default => ChatProviderInterface::class,
        };

        $next = $this->failover->nextEligible($capabilityInterface, $excluded);

        if ($next === null) {
            return null;
        }

        $this->metrics->increment('ai.failovers');

        $this->events->dispatch(new AIFailoverTriggeredEvent(
            $this->metadataFactory->create('AI', ['from' => $fromProviderId, 'to' => $next->id()]),
            fromProviderId: $fromProviderId,
            toProviderId: $next->id(),
            capability: $capability,
        ));

        return $next;
    }

    private function recordSuccess(?string $correlationId, ChatResponse $response, float $latencyMs, string $purpose): void
    {
        $costCents = $this->costCalculator->calculate($response->providerId, $response->model, $response->usage);

        $this->requestRepository->record(new AiRequestRecord(
            id: null,
            provider: $response->providerId,
            model: $response->model,
            purpose: $purpose,
            correlationId: $correlationId ?? $this->correlation->id(),
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            costCents: $costCents,
            status: 'success',
            error: null,
            durationMs: (int) $latencyMs,
            createdAt: EntityDates::now(),
        ));

        $this->metrics->increment('ai.requests_total');
        $this->metrics->record('ai.cost_cents', $costCents, ['provider' => $response->providerId]);
        $this->metrics->record('ai.tokens', $response->usage->totalTokens(), ['provider' => $response->providerId]);

        $this->events->dispatch(new AIRequestCompletedEvent(
            $this->metadataFactory->create('AI', ['provider' => $response->providerId]),
            providerId: $response->providerId,
            model: $response->model,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            costCents: $costCents,
            latencyMs: $latencyMs,
            fromCache: false,
        ));
    }

    private function recordFailure(?string $correlationId, string $providerId, string $model, AIException $exception): void
    {
        $this->requestRepository->record(new AiRequestRecord(
            id: null,
            provider: $providerId,
            model: $model,
            purpose: 'chat',
            correlationId: $correlationId ?? $this->correlation->id(),
            promptTokens: null,
            completionTokens: null,
            costCents: null,
            status: 'error',
            error: $exception->getMessage(),
            durationMs: null,
            createdAt: EntityDates::now(),
        ));

        $this->metrics->increment('ai.errors_total');

        $this->events->dispatch(new AIRequestFailedEvent(
            $this->metadataFactory->create('AI', ['provider' => $providerId]),
            providerId: $providerId,
            model: $model,
            errorType: $exception->errorType()->value,
            errorMessage: $exception->getMessage(),
        ));
    }

    private function rateLimitKeyFor(string $providerId): string
    {
        return 'ai_provider:' . $providerId;
    }
}

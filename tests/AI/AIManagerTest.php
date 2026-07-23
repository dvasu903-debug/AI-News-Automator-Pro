<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\Events\AIFailoverTriggeredEvent;
use AINewsAutomator\AI\Events\AIRequestCompletedEvent;
use AINewsAutomator\AI\Events\AIRequestFailedEvent;
use AINewsAutomator\AI\Exceptions\AIErrorType;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\Tests\AI\Fakes\AIManagerTestFactory;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

final class AIManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
        $GLOBALS['__ana_test_transients'] = [];
    }

    private function request(string $model = 'claude-sonnet-5'): ChatRequest
    {
        return new ChatRequest(messages: [Message::user('hello')], model: $model, maxTokens: 100);
    }

    public function test_happy_path_returns_response_and_records_request(): void
    {
        $provider = new FakeChatProvider('primary');
        $harness = AIManagerTestFactory::build([$provider]);

        $response = $harness->manager->chat($this->request());

        $this->assertSame('default response', $response->content);
        $this->assertFalse($response->fromCache);
        $this->assertCount(1, $harness->requestRepository->recorded);
        $this->assertSame('success', $harness->requestRepository->recorded[0]->status);
    }

    public function test_completed_event_is_dispatched_on_success(): void
    {
        $provider = new FakeChatProvider('primary');
        $harness = AIManagerTestFactory::build([$provider]);

        $fired = false;
        $harness->events->addListener(AIRequestCompletedEvent::class, function () use (&$fired): void {
            $fired = true;
        });

        $harness->manager->chat($this->request());

        $this->assertTrue($fired);
    }

    public function test_retries_transient_failure_then_succeeds(): void
    {
        $provider = new FakeChatProvider('primary');
        $provider->willThrow(new AIException('temporary outage', AIErrorType::ProviderOutage, 'primary'));
        $provider->willReturn(FakeChatProvider::successResponse('primary', 'recovered'));

        $harness = AIManagerTestFactory::build([$provider]);

        $response = $harness->manager->chat($this->request());

        $this->assertSame('recovered', $response->content);
        $this->assertSame(2, $provider->callCount, 'Should have attempted twice: fail then succeed.');
    }

    public function test_exhausts_retries_then_fails_over_to_next_provider(): void
    {
        $primary = new FakeChatProvider('primary');
        // Exceed the test factory's maxAttempts(3) with outages.
        $primary->willThrow(new AIException('down', AIErrorType::ProviderOutage, 'primary'));
        $primary->willThrow(new AIException('down', AIErrorType::ProviderOutage, 'primary'));
        $primary->willThrow(new AIException('down', AIErrorType::ProviderOutage, 'primary'));

        $backup = new FakeChatProvider('backup');
        $backup->willReturn(FakeChatProvider::successResponse('backup', 'from backup'));

        $harness = AIManagerTestFactory::build([$primary, $backup]);

        $failoverFired = false;
        $harness->events->addListener(AIFailoverTriggeredEvent::class, function () use (&$failoverFired): void {
            $failoverFired = true;
        });

        $response = $harness->manager->chat($this->request());

        $this->assertSame('from backup', $response->content);
        $this->assertTrue($failoverFired);
    }

    public function test_validation_error_never_retries_or_fails_over(): void
    {
        $primary = new FakeChatProvider('primary');
        $primary->willThrow(new AIException('bad request', AIErrorType::Validation, 'primary'));

        $backup = new FakeChatProvider('backup');
        $backup->willReturn(FakeChatProvider::successResponse('backup'));

        $harness = AIManagerTestFactory::build([$primary, $backup]);

        $this->expectException(AIException::class);

        try {
            $harness->manager->chat($this->request());
        } finally {
            $this->assertSame(1, $primary->callCount, 'Validation errors must not be retried.');
            $this->assertSame(0, $backup->callCount, 'Validation errors must not trigger failover.');
        }
    }

    public function test_authentication_error_never_retries_or_fails_over(): void
    {
        $primary = new FakeChatProvider('primary');
        $primary->willThrow(new AIException('bad key', AIErrorType::Authentication, 'primary'));

        $harness = AIManagerTestFactory::build([$primary]);

        $this->expectException(AIException::class);

        try {
            $harness->manager->chat($this->request());
        } finally {
            $this->assertSame(1, $primary->callCount);
        }
    }

    public function test_failed_request_is_recorded_and_event_dispatched(): void
    {
        $provider = new FakeChatProvider('primary');
        $provider->willThrow(new AIException('bad request', AIErrorType::Validation, 'primary'));

        $harness = AIManagerTestFactory::build([$provider]);

        $fired = false;
        $harness->events->addListener(AIRequestFailedEvent::class, function () use (&$fired): void {
            $fired = true;
        });

        try {
            $harness->manager->chat($this->request());
        } catch (AIException) {
            // expected
        }

        $this->assertTrue($fired);
        $this->assertCount(1, $harness->requestRepository->recorded);
        $this->assertSame('error', $harness->requestRepository->recorded[0]->status);
    }

    public function test_cache_hit_returns_cached_response_without_calling_provider(): void
    {
        $provider = new FakeChatProvider('primary');
        $provider->willReturn(FakeChatProvider::successResponse('primary', 'first-call'));

        $harness = AIManagerTestFactory::build([$provider]);
        $request = $this->request();

        $first = $harness->manager->chat($request);
        $second = $harness->manager->chat($request);

        $this->assertSame('first-call', $first->content);
        $this->assertSame('first-call', $second->content);
        $this->assertTrue($second->fromCache);
        $this->assertSame(1, $provider->callCount, 'Second identical request must be served from cache.');
    }

    public function test_different_requests_do_not_share_a_cache_entry(): void
    {
        $provider = new FakeChatProvider('primary');
        $harness = AIManagerTestFactory::build([$provider]);

        $harness->manager->chat($this->request());
        $harness->manager->chat(new ChatRequest(messages: [Message::user('different question')], model: 'claude-sonnet-5', maxTokens: 100));

        $this->assertSame(2, $provider->callCount);
    }

    public function test_explicit_provider_override_bypasses_default(): void
    {
        $primary = new FakeChatProvider('primary');
        $secondary = new FakeChatProvider('secondary');
        $secondary->willReturn(FakeChatProvider::successResponse('secondary', 'from secondary'));

        $harness = AIManagerTestFactory::build([$primary, $secondary]);

        $response = $harness->manager->chat($this->request(), providerId: 'secondary');

        $this->assertSame('from secondary', $response->content);
        $this->assertSame(0, $primary->callCount);
    }
}

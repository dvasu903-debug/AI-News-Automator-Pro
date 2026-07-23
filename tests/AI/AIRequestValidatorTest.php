<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\Exceptions\AIValidationException;
use AINewsAutomator\AI\Exceptions\UnsupportedCapabilityException;
use AINewsAutomator\AI\ModelCatalog\StaticModelCatalog;
use AINewsAutomator\AI\Validation\AIRequestValidator;
use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

final class AIRequestValidatorTest extends TestCase
{
    private AIRequestValidator $validator;

    protected function setUp(): void
    {
        $logger = new OptionBackedLogger(new CorrelationContext('test'), Environment::Development);
        $this->validator = new AIRequestValidator(new StaticModelCatalog($logger));
    }

    public function test_empty_messages_fails_shape_validation(): void
    {
        $request = new ChatRequest(messages: [], model: 'claude-sonnet-5');
        $this->expectException(AIValidationException::class);
        $this->validator->validateChatRequest($request, new FakeChatProvider());
    }

    public function test_non_positive_max_tokens_fails_shape_validation(): void
    {
        $request = new ChatRequest(messages: [Message::user('hi')], model: 'claude-sonnet-5', maxTokens: 0);
        $this->expectException(AIValidationException::class);
        $this->validator->validateChatRequest($request, new FakeChatProvider());
    }

    public function test_out_of_range_temperature_fails_shape_validation(): void
    {
        $request = new ChatRequest(messages: [Message::user('hi')], model: 'claude-sonnet-5', temperature: 5.0);
        $this->expectException(AIValidationException::class);
        $this->validator->validateChatRequest($request, new FakeChatProvider());
    }

    public function test_valid_request_passes(): void
    {
        $request = new ChatRequest(messages: [Message::user('hi')], model: 'claude-sonnet-5', maxTokens: 100);
        $this->validator->validateChatRequest($request, new FakeChatProvider());
        $this->assertTrue(true);
    }

    /**
     * A plain FakeChatProvider always structurally implements
     * VisionProviderInterface (PHP interfaces can't be conditionally
     * implemented per-instance), so the meaningful test of "capability
     * resolution is provider + selected model, not provider alone" is the
     * ModelCatalog per-model check — this targets exactly that: a known
     * model in the catalog that does NOT support vision.
     */
    public function test_vision_request_against_known_non_vision_model_throws(): void
    {
        $request = new ChatRequest(
            messages: [Message::userWithImage('describe this', 'https://example.test/image.png')],
            model: 'gpt-image-2', // known in the catalog, vision: false
        );

        $this->expectException(UnsupportedCapabilityException::class);
        $this->validator->validateChatRequest($request, new FakeChatProvider('openai'));
    }

    public function test_vision_request_against_capable_model_passes(): void
    {
        $request = new ChatRequest(
            messages: [Message::userWithImage('describe this', 'https://example.test/image.png')],
            model: 'claude-sonnet-5', // known in the catalog, vision: true
        );

        $this->validator->validateChatRequest($request, new FakeChatProvider('claude'));
        $this->assertTrue(true);
    }

    public function test_unknown_model_skips_catalog_check_but_still_requires_structural_capability(): void
    {
        // Model not in the static catalog at all — capabilitiesFor() returns
        // null, so only the coarse instanceof check applies.
        $request = new ChatRequest(
            messages: [Message::userWithImage('describe this', 'https://example.test/image.png')],
            model: 'some-unknown-future-model',
        );

        // FakeChatProvider structurally implements VisionProviderInterface,
        // so this should pass (no catalog entry to contradict it).
        $this->validator->validateChatRequest($request, new FakeChatProvider('claude'));
        $this->assertTrue(true);
    }

    public function test_request_exceeding_model_max_output_tokens_throws(): void
    {
        $request = new ChatRequest(
            messages: [Message::user('hi')],
            model: 'claude-haiku-4-5', // catalog: maxOutputTokens 64_000
            maxTokens: 999_999,
        );

        $this->expectException(AIValidationException::class);
        $this->validator->validateChatRequest($request, new FakeChatProvider('claude'));
    }

    public function test_structured_output_schema_request_passes_for_capable_model(): void
    {
        $request = new ChatRequest(
            messages: [Message::user('give me JSON')],
            model: 'claude-sonnet-5',
            responseSchema: ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]],
        );

        $this->validator->validateChatRequest($request, new FakeChatProvider('claude'));
        $this->assertTrue(true);
    }
}

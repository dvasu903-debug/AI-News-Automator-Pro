<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI;

use AINewsAutomator\AI\DTO\ChatChunk;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
use PHPUnit\Framework\TestCase;

final class StreamingTest extends TestCase
{
    public function test_stream_chat_yields_at_least_one_chunk(): void
    {
        $provider = new FakeChatProvider('primary');
        $provider->willReturn(FakeChatProvider::successResponse('primary', 'streamed content'));

        $request = new ChatRequest(messages: [Message::user('hi')], model: 'claude-sonnet-5');

        $chunks = iterator_to_array($provider->streamChat($request));

        $this->assertNotEmpty($chunks);
        $this->assertInstanceOf(ChatChunk::class, $chunks[0]);
    }

    public function test_final_chunk_is_marked_final_and_carries_usage(): void
    {
        $provider = new FakeChatProvider('primary');
        $provider->willReturn(FakeChatProvider::successResponse('primary', 'content', promptTokens: 20, completionTokens: 10));

        $request = new ChatRequest(messages: [Message::user('hi')], model: 'claude-sonnet-5');
        $chunks = iterator_to_array($provider->streamChat($request));

        $last = end($chunks);
        $this->assertTrue($last->isFinal);
        $this->assertNotNull($last->usage);
        $this->assertSame(20, $last->usage->promptTokens);
        $this->assertSame(10, $last->usage->completionTokens);
    }

    public function test_streamed_content_matches_the_underlying_response(): void
    {
        $provider = new FakeChatProvider('primary');
        $provider->willReturn(FakeChatProvider::successResponse('primary', 'exact content'));

        $request = new ChatRequest(messages: [Message::user('hi')], model: 'claude-sonnet-5');
        $chunks = iterator_to_array($provider->streamChat($request));

        $combined = implode('', array_map(static fn (ChatChunk $c): string => $c->delta, $chunks));
        $this->assertSame('exact content', $combined);
    }
}

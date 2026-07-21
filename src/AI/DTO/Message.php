<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * One turn in a chat conversation. Content is always a list of
 * ContentPart, even for plain text — a single-part text message is the
 * common case, multi-part is how vision requests are expressed.
 */
final class Message
{
    /**
     * @param list<ContentPart> $content
     */
    public function __construct(
        public readonly MessageRole $role,
        public readonly array $content,
        public readonly ?string $toolCallId = null,
    ) {
    }

    public static function system(string $text): self
    {
        return new self(MessageRole::System, [ContentPart::text($text)]);
    }

    public static function user(string $text): self
    {
        return new self(MessageRole::User, [ContentPart::text($text)]);
    }

    public static function userWithImage(string $text, string $imageUrl): self
    {
        return new self(MessageRole::User, [ContentPart::text($text), ContentPart::imageUrl($imageUrl)]);
    }

    public static function assistant(string $text): self
    {
        return new self(MessageRole::Assistant, [ContentPart::text($text)]);
    }

    /**
     * Plain-text convenience accessor — concatenates all text parts.
     * Most callers that aren't building vision requests just want this.
     */
    public function text(): string
    {
        $parts = array_filter($this->content, static fn (ContentPart $p): bool => $p->type === ContentPartType::Text);

        return implode('', array_map(static fn (ContentPart $p): string => (string) $p->text, $parts));
    }
}

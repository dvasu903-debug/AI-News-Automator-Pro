<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * One piece of a message's content — text or an image. A message with
 * multiple ContentParts (text + image) is what makes a request a vision
 * request; providers implementing VisionProviderInterface know how to
 * translate an Image part into their own wire format.
 */
final class ContentPart
{
    private function __construct(
        public readonly ContentPartType $type,
        public readonly ?string $text = null,
        public readonly ?string $imageUrl = null,
        public readonly ?string $imageBase64 = null,
        public readonly ?string $imageMediaType = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(ContentPartType::Text, text: $text);
    }

    public static function imageUrl(string $url): self
    {
        return new self(ContentPartType::Image, imageUrl: $url);
    }

    public static function imageBase64(string $base64, string $mediaType): self
    {
        return new self(ContentPartType::Image, imageBase64: $base64, imageMediaType: $mediaType);
    }
}

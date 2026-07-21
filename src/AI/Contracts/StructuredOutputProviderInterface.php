<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

/**
 * Marker interface. Certifies that ChatProviderInterface::chat() natively
 * enforces a JSON schema when ChatRequest::$responseSchema is set —
 * distinct from a provider that merely accepts the field and hopes the
 * model follows instructions.
 */
interface StructuredOutputProviderInterface extends AIProviderInterface
{
}

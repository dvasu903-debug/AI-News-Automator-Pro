<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

/**
 * Marker interface. Certifies that ChatProviderInterface::chat() accepts
 * ToolDefinition entries and can return ToolCall entries in its response.
 */
interface ToolCallingProviderInterface extends AIProviderInterface
{
}

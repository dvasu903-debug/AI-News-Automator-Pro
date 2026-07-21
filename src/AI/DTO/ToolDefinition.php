<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\DTO;

/**
 * A function/tool a model may call, described in the provider-agnostic
 * shape every ToolCallingProviderInterface adapter translates to its own
 * vendor's tool-definition format (Claude's input_schema, OpenAI's
 * parameters, etc.).
 */
final class ToolDefinition
{
    /**
     * @param array<string, mixed> $parametersSchema A JSON Schema object.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersSchema,
    ) {
    }
}

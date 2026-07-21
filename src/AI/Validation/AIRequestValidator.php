<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Validation;

use AINewsAutomator\AI\Contracts\AIProviderInterface;
use AINewsAutomator\AI\Contracts\AIRequestValidatorInterface;
use AINewsAutomator\AI\Contracts\ModelCatalogInterface;
use AINewsAutomator\AI\Contracts\StructuredOutputProviderInterface;
use AINewsAutomator\AI\Contracts\ToolCallingProviderInterface;
use AINewsAutomator\AI\Contracts\VisionProviderInterface;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\Exceptions\AIValidationException;
use AINewsAutomator\AI\Exceptions\UnsupportedCapabilityException;

/**
 * Validates request SHAPE and resolves capability as PROVIDER + SELECTED
 * MODEL, never provider alone (approved requirement). The coarse
 * instanceof check (does this provider class ever support vision)
 * answers structural eligibility; ModelCatalogInterface's per-model data,
 * when known, is the more specific check that takes precedence — a
 * provider that generally supports vision can still have a specific
 * model that doesn't, and this validator catches that before any HTTP
 * call is made.
 */
final class AIRequestValidator implements AIRequestValidatorInterface
{
    public function __construct(private readonly ModelCatalogInterface $catalog)
    {
    }

    public function validateChatRequest(ChatRequest $request, AIProviderInterface $resolvedProvider): void
    {
        $this->validateShape($request);
        $this->validateCapabilities($request, $resolvedProvider);
    }

    private function validateShape(ChatRequest $request): void
    {
        $errors = [];

        if ($request->messages === []) {
            $errors['messages'] = 'At least one message is required.';
        }

        if ($request->maxTokens <= 0) {
            $errors['maxTokens'] = 'maxTokens must be a positive integer.';
        }

        if (trim($request->model) === '') {
            $errors['model'] = 'model is required.';
        }

        if ($request->temperature < 0.0 || $request->temperature > 2.0) {
            $errors['temperature'] = 'temperature must be between 0 and 2.';
        }

        if ($errors !== []) {
            throw new AIValidationException($errors, 'Chat request failed shape validation.');
        }
    }

    private function validateCapabilities(ChatRequest $request, AIProviderInterface $resolvedProvider): void
    {
        $modelCapabilities = $this->catalog->capabilitiesFor($resolvedProvider->id(), $request->model);

        if ($request->requiresVision()) {
            if (!$resolvedProvider instanceof VisionProviderInterface) {
                throw UnsupportedCapabilityException::for($resolvedProvider->id(), 'vision', $request->model);
            }
            if ($modelCapabilities !== null && !$modelCapabilities->vision) {
                throw UnsupportedCapabilityException::for($resolvedProvider->id(), 'vision', $request->model);
            }
        }

        if ($request->requiresToolCalling()) {
            if (!$resolvedProvider instanceof ToolCallingProviderInterface) {
                throw UnsupportedCapabilityException::for($resolvedProvider->id(), 'tool_calling', $request->model);
            }
            if ($modelCapabilities !== null && !$modelCapabilities->toolCalling) {
                throw UnsupportedCapabilityException::for($resolvedProvider->id(), 'tool_calling', $request->model);
            }
        }

        if ($request->requiresStructuredOutput()) {
            if (!$resolvedProvider instanceof StructuredOutputProviderInterface) {
                throw UnsupportedCapabilityException::for($resolvedProvider->id(), 'structured_output', $request->model);
            }
            if ($modelCapabilities !== null && !$modelCapabilities->structuredOutput) {
                throw UnsupportedCapabilityException::for($resolvedProvider->id(), 'structured_output', $request->model);
            }
        }

        if ($modelCapabilities !== null && $modelCapabilities->maxOutputTokens > 0 && $request->maxTokens > $modelCapabilities->maxOutputTokens) {
            throw new AIValidationException(
                ['maxTokens' => sprintf(
                    'maxTokens (%d) exceeds model "%s"\'s maximum output of %d tokens.',
                    $request->maxTokens,
                    $request->model,
                    $modelCapabilities->maxOutputTokens
                )],
                'Chat request exceeds model limits.'
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\ChatRequest;

/**
 * Validates an AI request's SHAPE (required fields present, values in
 * range, requested capability actually available on the resolved
 * provider+model) before any provider is contacted. Deliberately a
 * distinct concept and class from Security\Request\RequestValidator,
 * which validates request AUTHORIZATION (nonce + capability + rate
 * limit) — different namespace, different responsibility, not a naming
 * collision in practice. See module README for the explicit disambiguation.
 */
interface AIRequestValidatorInterface
{
    /**
     * @throws \AINewsAutomator\AI\Exceptions\AIValidationException
     */
    public function validateChatRequest(ChatRequest $request, AIProviderInterface $resolvedProvider): void;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\ChatResponse;
use AINewsAutomator\AI\Exceptions\AIException;

interface ChatProviderInterface extends AIProviderInterface
{
    /**
     * @throws AIException Classified via AIErrorType — see RetryExecutor.
     */
    public function chat(ChatRequest $request): ChatResponse;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\EmbeddingRequest;
use AINewsAutomator\AI\DTO\EmbeddingResponse;

interface EmbeddingProviderInterface extends AIProviderInterface
{
    public function embed(EmbeddingRequest $request): EmbeddingResponse;
}

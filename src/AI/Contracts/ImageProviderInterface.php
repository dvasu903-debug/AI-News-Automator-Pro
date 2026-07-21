<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\ImageGenerationRequest;
use AINewsAutomator\AI\DTO\ImageGenerationResponse;

interface ImageProviderInterface extends AIProviderInterface
{
    public function generateImage(ImageGenerationRequest $request): ImageGenerationResponse;
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

use AINewsAutomator\AI\DTO\SpeechRequest;
use AINewsAutomator\AI\DTO\SpeechResponse;
use AINewsAutomator\AI\DTO\TranscriptionRequest;
use AINewsAutomator\AI\DTO\TranscriptionResponse;

interface SpeechProviderInterface extends AIProviderInterface
{
    public function synthesize(SpeechRequest $request): SpeechResponse;

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse;
}

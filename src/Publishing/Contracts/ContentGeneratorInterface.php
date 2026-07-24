<?php

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\Publishing\DTO\GeneratedContent;
use AINewsAutomator\Publishing\Exceptions\ContentGenerationException;
use AINewsAutomator\Research\DTO\ResearchSummary;

/**
 * Turns a completed research session's ResearchSummary into draft
 * article content. This is the trust boundary where AI-provider output
 * first enters Publishing (see ADR-0019, decision 3) — the concrete
 * implementation is responsible for sanitizing whatever it returns;
 * callers (GenerateAction) must not need to sanitize again.
 */
interface ContentGeneratorInterface
{
    /**
     * @throws ContentGenerationException When no reviewed prompt template is configured, or the provider's response cannot be interpreted as content.
     * @throws AIException When the underlying AI call itself fails — callers translate this into a workflow retry decision (see GenerateAction).
     */
    public function generate(ResearchSummary $summary): GeneratedContent;
}

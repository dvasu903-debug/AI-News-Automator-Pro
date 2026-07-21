<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Extraction;

use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Research\Contracts\ClaimExtractorInterface;
use AINewsAutomator\Research\DTO\ExtractedClaimData;
use AINewsAutomator\Research\Entities\Evidence;

/**
 * Extracts factual claim statements from one piece of Evidence via
 * AIManager's structured output — reused, not reimplemented (no direct
 * HTTP call, no provider-specific code anywhere in this class).
 *
 * Graceful degradation: an extraction failure (provider outage, bad
 * response shape) logs and returns an empty list rather than throwing —
 * one failed evidence extraction should not abort an entire research
 * session's analysis pass.
 */
final class AiClaimExtractor implements ClaimExtractorInterface
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'claims' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'statement'  => ['type' => 'string'],
                        'confidence' => ['type' => 'number'],
                    ],
                    'required' => ['statement', 'confidence'],
                ],
            ],
        ],
        'required' => ['claims'],
    ];

    public function __construct(
        private readonly AIManager $aiManager,
        private readonly ConfigRepositoryInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function extract(Evidence $evidence): array
    {
        if ($evidence->snippet === null || trim($evidence->snippet) === '') {
            return [];
        }

        $request = new ChatRequest(
            messages: [
                Message::system('You extract discrete, checkable factual claims from source text. Extract only claims explicitly stated in the text — never infer or add information not present. Each claim should be a single, self-contained factual statement.'),
                Message::user(sprintf("Source: %s\n\nText:\n%s", $evidence->sourceUrl, $evidence->snippet)),
            ],
            model: (string) $this->config->get('research.extraction.model', 'claude-sonnet-5'),
            maxTokens: 1024,
            responseSchema: self::RESPONSE_SCHEMA,
        );

        try {
            $response = $this->aiManager->chat($request);
        } catch (AIException $e) {
            $this->logger->warning('Claim extraction failed for evidence {url}: {error}', [
                'url'   => $evidence->sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $decoded = json_decode($response->content, true);

        if (!is_array($decoded) || !isset($decoded['claims']) || !is_array($decoded['claims'])) {
            $this->logger->warning('Claim extraction returned an unexpected response shape for evidence {url}.', [
                'url' => $evidence->sourceUrl,
            ]);

            return [];
        }

        $results = [];
        foreach ($decoded['claims'] as $claim) {
            if (!isset($claim['statement']) || trim((string) $claim['statement']) === '') {
                continue;
            }

            $results[] = new ExtractedClaimData(
                statement: (string) $claim['statement'],
                extractionConfidence: max(0.0, min(1.0, (float) ($claim['confidence'] ?? 0.5))),
            );
        }

        return $results;
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Extraction;

use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Research\Contracts\EntityExtractorInterface;
use AINewsAutomator\Research\DTO\ExtractedEntityData;
use AINewsAutomator\Research\Entities\Evidence;

/**
 * Extracts named entities (person/organization/place/event) from one
 * piece of Evidence via AIManager's structured output. Same graceful-
 * degradation posture as AiClaimExtractor — a failure here never aborts
 * the session.
 */
final class AiEntityExtractor implements EntityExtractorInterface
{
    private const VALID_TYPES = ['person', 'organization', 'place', 'event'];

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'entities' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => ['person', 'organization', 'place', 'event']],
                    ],
                    'required' => ['name', 'type'],
                ],
            ],
        ],
        'required' => ['entities'],
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
                Message::system('You extract named entities (people, organizations, places, events) explicitly mentioned in source text. Do not infer entities not directly named.'),
                Message::user($evidence->snippet),
            ],
            model: (string) $this->config->get('research.extraction.model', 'claude-sonnet-5'),
            maxTokens: 512,
            responseSchema: self::RESPONSE_SCHEMA,
        );

        try {
            $response = $this->aiManager->chat($request);
        } catch (AIException $e) {
            $this->logger->warning('Entity extraction failed for evidence {url}: {error}', [
                'url'   => $evidence->sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $decoded = json_decode($response->content, true);

        if (!is_array($decoded) || !isset($decoded['entities']) || !is_array($decoded['entities'])) {
            return [];
        }

        $results = [];
        foreach ($decoded['entities'] as $entity) {
            $name = trim((string) ($entity['name'] ?? ''));
            $type = (string) ($entity['type'] ?? '');

            if ($name === '' || !in_array($type, self::VALID_TYPES, true)) {
                continue;
            }

            $results[] = new ExtractedEntityData($name, $type);
        }

        return $results;
    }
}

<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Contradiction;

use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\Exceptions\AIException;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Research\Contracts\ContradictionDetectorInterface;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\Contradiction;
use AINewsAutomator\Research\Entities\ContradictionSeverity;
use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * Compares ONE new claim against a session's existing claims via a
 * SINGLE batched AI call (all existing claims in one request), not N
 * pairwise calls — bounded cost per new claim regardless of how many
 * existing claims the session has accumulated. Returned Contradiction
 * entities are NOT yet persisted — ResearchSessionManager persists them
 * (this class only detects, mirroring the extractors' separation of
 * "figure out what's true" from "write it down").
 */
final class AiContradictionDetector implements ContradictionDetectorInterface
{
    private const MAX_EXISTING_CLAIMS_PER_CALL = 30;

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'contradictions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'existing_claim_index' => ['type' => 'integer'],
                        'description'          => ['type' => 'string'],
                        'severity'             => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                    ],
                    'required' => ['existing_claim_index', 'description', 'severity'],
                ],
            ],
        ],
        'required' => ['contradictions'],
    ];

    public function __construct(
        private readonly AIManager $aiManager,
        private readonly ConfigRepositoryInterface $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function detectFor(Claim $newClaim, array $existingClaims): array
    {
        if ($existingClaims === []) {
            return [];
        }

        // Bound the comparison set — a session with an unusually large
        // number of claims compares against only the most recent ones
        // rather than growing the prompt unboundedly.
        $comparisonSet = array_slice($existingClaims, -self::MAX_EXISTING_CLAIMS_PER_CALL);

        $numberedClaims = [];
        foreach ($comparisonSet as $index => $claim) {
            $numberedClaims[] = sprintf('[%d] %s', $index, $claim->statement);
        }

        $request = new ChatRequest(
            messages: [
                Message::system('You detect direct factual contradictions between claims. A contradiction means two claims cannot both be true — not merely different emphasis or incomplete information. Rate severity: "low" for minor variance (e.g. rounding), "medium" for a meaningful disagreement, "high" for directly opposing claims, "critical" for opposing claims that would mislead if published together.'),
                Message::user(sprintf(
                    "New claim: %s\n\nExisting claims:\n%s\n\nWhich existing claims (by index) does the new claim contradict, if any?",
                    $newClaim->statement,
                    implode("\n", $numberedClaims)
                )),
            ],
            model: (string) $this->config->get('research.extraction.model', 'claude-sonnet-5'),
            maxTokens: 1024,
            responseSchema: self::RESPONSE_SCHEMA,
        );

        try {
            $response = $this->aiManager->chat($request);
        } catch (AIException $e) {
            $this->logger->warning('Contradiction detection failed for claim {claim}: {error}', [
                'claim' => $newClaim->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $decoded = json_decode($response->content, true);

        if (!is_array($decoded) || !isset($decoded['contradictions']) || !is_array($decoded['contradictions'])) {
            return [];
        }

        $results = [];
        foreach ($decoded['contradictions'] as $item) {
            $index = (int) ($item['existing_claim_index'] ?? -1);

            if (!isset($comparisonSet[$index])) {
                continue;
            }

            $existingClaim = $comparisonSet[$index];
            $severity = ContradictionSeverity::tryFrom((string) ($item['severity'] ?? 'medium')) ?? ContradictionSeverity::Medium;

            $results[] = new Contradiction(
                id: null,
                sessionId: $newClaim->sessionId,
                claimAId: (int) $existingClaim->id,
                claimBId: (int) $newClaim->id,
                description: (string) ($item['description'] ?? 'Contradiction detected.'),
                severity: $severity,
                resolved: false,
                createdAt: EntityDates::now(),
            );
        }

        return $results;
    }
}

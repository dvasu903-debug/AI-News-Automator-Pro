<?php

declare(strict_types=1);

namespace AINewsAutomator\Research\Api;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\RestApi\AbstractRestController;
use AINewsAutomator\Research\Authorization\ResearchAbilityPolicy;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Research\Exceptions\ResearchException;
use AINewsAutomator\Research\Session\ResearchSessionManager;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;

/**
 * REST surface for research sessions. Uses Security's
 * RestSecurityMiddleware::requireAbility() (ability-based, going through
 * PolicyEngine + audit) rather than AbstractRestController's more basic
 * requireCapability() helper — the richer, audited check is the right
 * default for a module explicitly built around provenance and trust.
 */
final class ResearchSessionController extends AbstractRestController
{
    public function __construct(
        ConfigRepositoryInterface $config,
        private readonly RestSecurityMiddleware $security,
        private readonly SessionRepositoryInterface $sessions,
        private readonly ResearchSessionManager $manager,
    ) {
        parent::__construct($config);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->namespace(), '/research/sessions', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => $this->security->requireAbility(ResearchAbilityPolicy::VIEW),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'start'],
                'permission_callback' => $this->security->requireAbility(ResearchAbilityPolicy::MANAGE),
            ],
        ]);

        register_rest_route($this->namespace(), '/research/sessions/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => $this->security->requireAbility(ResearchAbilityPolicy::VIEW),
        ]);

        register_rest_route($this->namespace(), '/research/sessions/(?P<id>\d+)/summary', [
            'methods'             => 'GET',
            'callback'            => [$this, 'summary'],
            'permission_callback' => $this->security->requireAbility(ResearchAbilityPolicy::VIEW),
        ]);

        register_rest_route($this->namespace(), '/research/sessions/(?P<id>\d+)/evidence', [
            'methods'             => 'POST',
            'callback'            => [$this, 'addEvidence'],
            'permission_callback' => $this->security->requireAbility(ResearchAbilityPolicy::MANAGE),
        ]);

        register_rest_route($this->namespace(), '/research/sessions/(?P<id>\d+)/analyze', [
            'methods'             => 'POST',
            'callback'            => [$this, 'analyze'],
            'permission_callback' => $this->security->requireAbilityWithRateLimit(ResearchAbilityPolicy::MANAGE, 'research_analyze', 10, 3600),
        ]);

        register_rest_route($this->namespace(), '/research/sessions/(?P<id>\d+)/abandon', [
            'methods'             => 'POST',
            'callback'            => [$this, 'abandon'],
            'permission_callback' => $this->security->requireAbility(ResearchAbilityPolicy::MANAGE),
        ]);
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $statusParam = $request->get_param('status');
        $status = is_string($statusParam) ? SessionStatus::tryFrom($statusParam) : null;

        $sessions = $status !== null
            ? $this->sessions->byStatus($status)
            : $this->sessions->byStatus(SessionStatus::Completed);

        return $this->success(array_map(static fn ($s) => [
            'id'          => $s->id,
            'topic'       => $s->topic,
            'status'      => $s->status->value,
            'confidence'  => $s->confidenceScore,
            'created_at'  => $s->createdAt->format(DATE_ATOM),
        ], $sessions));
    }

    public function start(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $topic = (string) $request->get_param('topic');

        if (trim($topic) === '') {
            return $this->error('ana_invalid_request', 'A topic is required.', 422);
        }

        $vertical = (string) ($request->get_param('vertical') ?? 'news');
        $sessionId = $this->manager->startSession($topic, $vertical);

        return $this->success(['session_id' => $sessionId], 201);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $session = $this->sessions->find((int) $request->get_param('id'));

        if ($session === null) {
            return $this->error('ana_not_found', 'Research session not found.', 404);
        }

        return $this->success([
            'id'         => $session->id,
            'topic'      => $session->topic,
            'status'     => $session->status->value,
            'confidence' => $session->confidenceScore,
            'cluster'    => $session->topicCluster,
        ]);
    }

    public function summary(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $summary = $this->sessions->summarize((int) $request->get_param('id'));
        } catch (ResearchException $e) {
            return $this->error('ana_invalid_state', $e->getMessage(), 409);
        }

        return $this->success([
            'session_id'          => $summary->sessionId,
            'topic'               => $summary->topic,
            'overall_confidence'  => $summary->overallConfidence,
            'claim_count'         => count($summary->claims),
            'citation_count'      => $summary->citationCount(),
            'has_blocking_contradictions' => $summary->hasBlockingContradictions(),
        ]);
    }

    public function addEvidence(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $evidenceId = $this->manager->addEvidence(
                (int) $request->get_param('id'),
                (string) $request->get_param('source_url'),
                (string) ($request->get_param('source_type') ?? 'unknown'),
                (string) ($request->get_param('domain') ?? ''),
                $request->get_param('credibility_score') !== null ? (float) $request->get_param('credibility_score') : null,
                $request->get_param('snippet') !== null ? (string) $request->get_param('snippet') : null,
                null,
            );
        } catch (ResearchException $e) {
            return $this->error('ana_invalid_state', $e->getMessage(), 409);
        }

        return $this->success(['evidence_id' => $evidenceId], 201);
    }

    public function analyze(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $summary = $this->manager->analyzeSession((int) $request->get_param('id'));
        } catch (ResearchException $e) {
            return $this->error('ana_invalid_state', $e->getMessage(), 409);
        }

        return $this->success(['session_id' => $summary->sessionId, 'overall_confidence' => $summary->overallConfidence]);
    }

    public function abandon(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        try {
            $this->manager->abandonSession((int) $request->get_param('id'));
        } catch (ResearchException $e) {
            return $this->error('ana_invalid_state', $e->getMessage(), 409);
        }

        return $this->success(['abandoned' => true]);
    }
}

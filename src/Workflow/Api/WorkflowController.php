<?php

declare(strict_types=1);

namespace AINewsAutomator\Workflow\Api;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\RestApi\AbstractRestController;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;
use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Workflow\Authorization\WorkflowAbilityPolicy;
use AINewsAutomator\Workflow\Contracts\WorkflowDefinitionRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowRunRepositoryInterface;
use AINewsAutomator\Workflow\Contracts\WorkflowStepResultRepositoryInterface;
use AINewsAutomator\Workflow\Entities\WorkflowDefinitionVersion;
use AINewsAutomator\Workflow\Exceptions\WorkflowException;
use AINewsAutomator\Workflow\Runner\WorkflowRunner;
use AINewsAutomator\Storage\Entities\EntityDates;

/**
 * REST surface for Workflow. Uses Security's
 * RestSecurityMiddleware::requireAbility() (ability-based, going
 * through PolicyEngine + audit) for every route — same as Research —
 * rather than AbstractRestController's more basic requireCapability(),
 * given this module governs run execution and approval decisions.
 * This is also the ApiTrigger's actual entry point: POST
 * /workflow/definitions/{key}/run is what an "api"-triggered workflow
 * run means in practice.
 */
final class WorkflowController extends AbstractRestController
{
    public function __construct(
        ConfigRepositoryInterface $config,
        private readonly RestSecurityMiddleware $security,
        private readonly WorkflowDefinitionRepositoryInterface $definitions,
        private readonly WorkflowRunRepositoryInterface $runs,
        private readonly WorkflowStepResultRepositoryInterface $stepResults,
        private readonly WorkflowRunner $runner,
    ) {
        parent::__construct($config);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->namespace(), '/workflow/definitions', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'listDefinitions'],
                'permission_callback' => $this->security->requireAbility(WorkflowAbilityPolicy::VIEW),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'saveDefinition'],
                'permission_callback' => $this->security->requireAbility(WorkflowAbilityPolicy::MANAGE),
            ],
        ]);

        register_rest_route($this->namespace(), '/workflow/definitions/(?P<key>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'showDefinition'],
            'permission_callback' => $this->security->requireAbility(WorkflowAbilityPolicy::VIEW),
        ]);

        register_rest_route($this->namespace(), '/workflow/definitions/(?P<key>[a-zA-Z0-9_\-]+)/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'runDefinition'],
            'permission_callback' => $this->security->requireAbilityWithRateLimit(WorkflowAbilityPolicy::MANAGE, 'workflow_run', 30, 3600),
        ]);

        register_rest_route($this->namespace(), '/workflow/runs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'showRun'],
            'permission_callback' => $this->security->requireAbility(WorkflowAbilityPolicy::VIEW),
        ]);

        register_rest_route($this->namespace(), '/workflow/runs/(?P<id>\d+)/approvals/(?P<step_key>[a-zA-Z0-9_\-]+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'decideApproval'],
            'permission_callback' => $this->security->requireAbility(WorkflowAbilityPolicy::APPROVE),
        ]);
    }

    public function listDefinitions(\WP_REST_Request $request): \WP_REST_Response
    {
        $keys = $this->definitions->allKeys();

        $rows = array_map(function (string $key): array {
            $latest = $this->definitions->latest($key);

            return [
                'workflow_key'   => $key,
                'latest_version' => $latest?->version,
            ];
        }, $keys);

        return $this->success($rows);
    }

    public function showDefinition(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = (string) $request->get_param('key');
        $version = $this->definitions->latest($key);

        if ($version === null) {
            return $this->error('ana_not_found', 'No workflow definition found for this key.', 404);
        }

        return $this->success([
            'workflow_key' => $version->workflowKey,
            'version'      => $version->version,
            'definition'   => $version->definition,
            'created_at'   => $version->createdAt->format(DATE_ATOM),
        ]);
    }

    /**
     * Saves a new immutable version of a workflow definition (write-once
     * — Part 1, Option A). There is deliberately no PUT/PATCH route:
     * every save is a new version, never an overwrite.
     */
    public function saveDefinition(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $workflowKey = (string) $request->get_param('workflow_key');
        $definitionJson = $request->get_param('definition');

        if (trim($workflowKey) === '' || !is_array($definitionJson)) {
            return $this->error('ana_invalid_request', 'workflow_key and a definition object are required.', 422);
        }

        $current = $this->definitions->latest($workflowKey);
        $nextVersion = $current === null ? 1 : $current->version + 1;

        try {
            $id = $this->definitions->saveNewVersion(new WorkflowDefinitionVersion(
                id: null,
                workflowKey: $workflowKey,
                version: $nextVersion,
                definition: $definitionJson,
                createdAt: EntityDates::now(),
            ));
        } catch (ValidationException $e) {
            return $this->error('ana_invalid_definition', $e->getMessage(), 422);
        }

        return $this->success(['id' => $id, 'workflow_key' => $workflowKey, 'version' => $nextVersion], 201);
    }

    public function runDefinition(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $key = (string) $request->get_param('key');
        $userId = get_current_user_id();

        try {
            $run = $this->runner->run($key, 'api', $userId > 0 ? $userId : null);
        } catch (WorkflowException $e) {
            return $this->error('ana_workflow_error', $e->getMessage(), 409);
        }

        return $this->success([
            'run_id'       => $run->id,
            'workflow_key' => $run->workflowKey,
            'version'      => $run->version,
            'status'       => $run->status->value,
        ], 201);
    }

    public function showRun(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $run = $this->runs->find((int) $request->get_param('id'));

        if ($run === null) {
            return $this->error('ana_not_found', 'Workflow run not found.', 404);
        }

        $steps = array_map(static fn ($s) => [
            'step_key'    => $s->stepKey,
            'action_type' => $s->actionType,
            'status'      => $s->status->value,
            'error'       => $s->error,
        ], $this->stepResults->forRun((int) $run->id));

        return $this->success([
            'id'               => $run->id,
            'workflow_key'     => $run->workflowKey,
            'version'          => $run->version,
            'status'           => $run->status->value,
            'current_step_key' => $run->currentStepKey,
            'error'            => $run->error,
            'steps'            => $steps,
        ]);
    }

    public function decideApproval(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $runId = (int) $request->get_param('id');
        $stepKey = (string) $request->get_param('step_key');
        $decisionParam = (string) $request->get_param('decision');
        $reason = $request->get_param('reason') !== null ? (string) $request->get_param('reason') : null;

        if (!in_array($decisionParam, ['approve', 'reject'], true)) {
            return $this->error('ana_invalid_request', 'decision must be "approve" or "reject".', 422);
        }

        $userId = get_current_user_id();

        try {
            $run = $this->runner->approve($runId, $stepKey, $userId, $decisionParam === 'approve', $reason);
        } catch (WorkflowException $e) {
            return $this->error('ana_workflow_error', $e->getMessage(), 409);
        }

        return $this->success(['run_id' => $run->id, 'status' => $run->status->value]);
    }
}

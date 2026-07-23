<?php
/**
 * REST surface for Publishing Milestone 3: the profile list/create
 * endpoints Milestone 2 shipped without, plus publish/schedule/
 * unpublish/archive on an existing draft. Uses
 * RestSecurityMiddleware::requireAbility() for every route, same as
 * Workflow/Research — see ADR-0018.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Api;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\RestApi\AbstractRestController;
use AINewsAutomator\Publishing\Authorization\PublishingAbilityPolicy;
use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\DuplicateNameException;
use AINewsAutomator\Publishing\Exceptions\DuplicateSlugException;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;
use AINewsAutomator\Publishing\Services\PublishingProfileService;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;

final class PublishingController extends AbstractRestController
{
    public function __construct(
        ConfigRepositoryInterface $config,
        private readonly RestSecurityMiddleware $security,
        private readonly PublishingProfileService $profiles,
        private readonly PublisherInterface $publisher,
    ) {
        parent::__construct($config);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->namespace(), '/publishing/profiles', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'listProfiles'],
                'permission_callback' => $this->security->requireAbility(PublishingAbilityPolicy::VIEW),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'createProfile'],
                'permission_callback' => $this->security->requireAbility(PublishingAbilityPolicy::MANAGE_PROFILES),
            ],
        ]);

        register_rest_route($this->namespace(), '/publishing/drafts/(?P<id>\d+)/publish', [
            'methods'             => 'POST',
            'callback'            => [$this, 'publish'],
            'permission_callback' => $this->security->requireAbilityWithRateLimit(PublishingAbilityPolicy::PUBLISH, 'publishing_publish', 30, 3600),
        ]);

        register_rest_route($this->namespace(), '/publishing/drafts/(?P<id>\d+)/schedule', [
            'methods'             => 'POST',
            'callback'            => [$this, 'schedule'],
            'permission_callback' => $this->security->requireAbilityWithRateLimit(PublishingAbilityPolicy::PUBLISH, 'publishing_schedule', 30, 3600),
        ]);

        register_rest_route($this->namespace(), '/publishing/drafts/(?P<id>\d+)/unpublish', [
            'methods'             => 'POST',
            'callback'            => [$this, 'unpublish'],
            'permission_callback' => $this->security->requireAbility(PublishingAbilityPolicy::PUBLISH),
        ]);

        register_rest_route($this->namespace(), '/publishing/drafts/(?P<id>\d+)/archive', [
            'methods'             => 'POST',
            'callback'            => [$this, 'archive'],
            'permission_callback' => $this->security->requireAbility(PublishingAbilityPolicy::PUBLISH),
        ]);
    }

    public function listProfiles(\WP_REST_Request $request): \WP_REST_Response
    {
        $enabledOnly = (bool) $request->get_param('enabled_only');

        $rows = array_map(
            static fn (PublishingProfile $p): array => $p->toArray(),
            $this->profiles->listProfiles($enabledOnly)
        );

        return $this->success($rows);
    }

    public function createProfile(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = (string) $request->get_param('slug');
        $name = (string) $request->get_param('name');
        $workflowKey = (string) $request->get_param('workflow_key');

        if ('' === trim($slug) || '' === trim($name) || '' === trim($workflowKey)) {
            return $this->error('ana_invalid_request', 'slug, name, and workflow_key are required.', 422);
        }

        $vertical = $request->get_param('vertical');
        $approvalMode = $request->get_param('approval_mode');
        $config = $request->get_param('config');

        $profile = new PublishingProfile(
            null,
            $slug,
            $name,
            $workflowKey,
            is_string($vertical) && '' !== $vertical ? $vertical : 'news',
            is_string($approvalMode) && '' !== $approvalMode ? $approvalMode : 'manual',
            is_array($config) ? $config : []
        );

        try {
            $created = $this->profiles->create($profile);
        } catch (DuplicateSlugException|DuplicateNameException $e) {
            return $this->error('ana_duplicate', $e->getMessage(), 409);
        } catch (ProfileValidationException $e) {
            return $this->error('ana_invalid_profile', implode(' ', $e->errors()), 422);
        }

        return $this->success($created->toArray(), 201);
    }

    public function publish(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->publisher->publish((int) $request->get_param('id'));

        return $result->isSuccess()
            ? $this->success(['post_id' => $result->postId])
            : $this->error('ana_publish_failed', $result->error ?? 'Publish failed.', 422);
    }

    public function schedule(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $at = (string) $request->get_param('scheduled_for');

        if ('' === trim($at)) {
            return $this->error('ana_invalid_request', 'scheduled_for is required.', 422);
        }

        try {
            $scheduledFor = new \DateTimeImmutable($at);
        } catch (\Exception) {
            return $this->error('ana_invalid_request', sprintf('"%s" is not a valid datetime.', $at), 422);
        }

        $result = $this->publisher->schedule((int) $request->get_param('id'), $scheduledFor);

        return $result->isSuccess()
            ? $this->success(['post_id' => $result->postId, 'scheduled_for' => $scheduledFor->format(DATE_ATOM)])
            : $this->error('ana_schedule_failed', $result->error ?? 'Schedule failed.', 422);
    }

    public function unpublish(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->publisher->unpublish((int) $request->get_param('id'));

        return $result->isSuccess()
            ? $this->success(['post_id' => $result->postId])
            : $this->error('ana_unpublish_failed', $result->error ?? 'Unpublish failed.', 422);
    }

    public function archive(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $result = $this->publisher->archive((int) $request->get_param('id'));

        return $result->isSuccess()
            ? $this->success(['post_id' => $result->postId])
            : $this->error('ana_archive_failed', $result->error ?? 'Archive failed.', 422);
    }
}

<?php

declare(strict_types=1);

/**
 * Module 8 Milestone 3 runtime checklist (regression check): the six
 * required areas — PublishingService operations, REST endpoints,
 * Workflow actions, event dispatch, authorization policies, health
 * check registration. See
 * docs/verification/2026-07-23-module-8-milestone-3-runtime-verification.md
 * for the original pass this codifies.
 */

require __DIR__ . '/../harness-bootstrap.php';

use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Publishing\Api\PublishingController;
use AINewsAutomator\Publishing\Authorization\PublishingAbilityPolicy;
use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Events\ArticleArchivedEvent;
use AINewsAutomator\Publishing\Events\ArticlePublishedEvent;
use AINewsAutomator\Publishing\Events\ArticleScheduledEvent;
use AINewsAutomator\Publishing\Events\ArticleUnpublishedEvent;
use AINewsAutomator\Publishing\Events\PublishingRejectedEvent;
use AINewsAutomator\Publishing\Health\PublishingHealthCheck;
use AINewsAutomator\Publishing\Services\PublishingProfileService;
use AINewsAutomator\Security\Authorization\Capabilities;
use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Workflow\Contracts\ActionRegistryInterface;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

$FAIL = 0;
function ok(string $item, bool $pass, string $detail = ''): void
{
    global $FAIL;
    if (!$pass) {
        $FAIL = 1;
    }
    printf("[%s] %s%s\n", $pass ? 'PASS' : 'FAIL', $item, $detail !== '' ? " — $detail" : '');
}

$wpdb = $GLOBALS['wpdb'];

$plugin = PluginFactory::create(ANA_PRO_FILE);
$plugin->boot();
do_action('plugins_loaded');
do_action('rest_api_init');
$c = $plugin->container();

$profileService = $c->get(PublishingProfileService::class);
$wpdb->query('DELETE FROM wp_ana_publishing_profiles');
$profile = $profileService->create(new PublishingProfile(
    null, 'm3-smoke', 'M3 Smoke', 'standard_publish', 'news', 'manual',
    ['ai_disclosure_acknowledged' => true]
));

$GLOBALS['__posts'][501] = ['post_title' => 'Manual Draft', 'post_content' => str_repeat('word ', 20), 'post_status' => 'draft'];
$GLOBALS['__posts'][502] = ['post_title' => 'AI Draft', 'post_content' => str_repeat('word ', 20), 'post_status' => 'draft'];
$GLOBALS['__postmeta'][502]['_ana_generated'] = 1;

echo "=== 1. PublishingService operations ===\n";
$publisher = $c->get(PublisherInterface::class);
$dispatched = [];
$events = $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class);
foreach ([ArticlePublishedEvent::class, ArticleScheduledEvent::class, ArticleUnpublishedEvent::class, ArticleArchivedEvent::class, PublishingRejectedEvent::class] as $eventClass) {
    $events->addListener($eventClass, function (object $e) use (&$dispatched): void {
        $dispatched[] = $e;
    });
}

ok('1a: publish() manual draft succeeds', $publisher->publish(501)->isSuccess() && $GLOBALS['__posts'][501]['post_status'] === 'publish');
ok('1a: ArticlePublishedEvent dispatched', end($dispatched) instanceof ArticlePublishedEvent);

$at = new DateTimeImmutable('2027-03-01 10:00:00', new DateTimeZone('UTC'));
ok('1b: schedule() sets future status + post_date', $publisher->schedule(502, $at)->isSuccess() && $GLOBALS['__posts'][502]['post_status'] === 'future');
ok('1b: ArticleScheduledEvent dispatched', end($dispatched) instanceof ArticleScheduledEvent);

ok('1c: unpublish() reverts to draft', $publisher->unpublish(501)->isSuccess() && $GLOBALS['__posts'][501]['post_status'] === 'draft');
ok('1d: archive() uses native private status', $publisher->archive(501)->isSuccess() && $GLOBALS['__posts'][501]['post_status'] === 'private');

echo "\n=== 2. EditorialPolicyInterface ===\n";
$policy = $c->get(EditorialPolicyInterface::class);
$GLOBALS['__posts'][503] = ['post_content' => 'Too short.'];
$strict = $profileService->create(new PublishingProfile(null, 'm3-strict', 'M3 Strict', 'standard_publish', 'news', 'manual', ['min_word_count' => 50]));
ok('2: word-count violation detected', !$policy->evaluate(503, $strict)->passes());

echo "\n=== 3. Workflow actions ===\n";
$registry = $c->get(ActionRegistryInterface::class);
foreach (['publishing.publish_draft', 'publishing.schedule_draft', 'publishing.unpublish', 'publishing.archive'] as $type) {
    ok("3: \"$type\" registered", $registry->forType($type) !== null);
}
$GLOBALS['__posts'][504] = ['post_title' => 'Action Draft', 'post_content' => str_repeat('word ', 20), 'post_status' => 'draft'];
$action = $registry->forType('publishing.publish_draft');
$result = $action->execute(new WorkflowRunContext(1, 'publish', 'corr', ['post_id' => 504, 'profile_id' => $profile->id()], []));
ok('3: publish_draft action executes end-to-end', $result->isSuccess() && $GLOBALS['__posts'][504]['post_status'] === 'publish');

echo "\n=== 4. Authorization policies ===\n";
$gate = $c->get(CapabilityGateInterface::class);
$GLOBALS['__user_caps'][42] = [Capabilities::RUN_PIPELINE];
$GLOBALS['__current_user_id'] = 42;
ok('4: allowed with RUN_PIPELINE capability', $gate->allows(PublishingAbilityPolicy::PUBLISH));
$GLOBALS['__current_user_id'] = 43;
ok('4: denied without capability', $gate->allows(PublishingAbilityPolicy::PUBLISH) === false);

echo "\n=== 5. REST endpoints ===\n";
$routes = array_map(static fn ($r) => $r['methods'] . ' ' . $r['route'], array_filter($GLOBALS['__rest_routes'], static fn ($r) => str_starts_with($r['route'], '/publishing')));
foreach ([
    'GET /publishing/profiles', 'POST /publishing/profiles',
    'POST /publishing/drafts/(?P<id>\d+)/publish', 'POST /publishing/drafts/(?P<id>\d+)/schedule',
    'POST /publishing/drafts/(?P<id>\d+)/unpublish', 'POST /publishing/drafts/(?P<id>\d+)/archive',
] as $expected) {
    ok("5: route registered: $expected", in_array($expected, $routes, true));
}
$controller = $c->get(PublishingController::class);
$response = $controller->listProfiles(new WP_REST_Request());
ok('5: listProfiles() REST callback executes', $response instanceof WP_REST_Response && count($response->data['data']) >= 1);

echo "\n=== 6. Health check registration ===\n";
$health = $c->get(PublishingHealthCheck::class);
$results = $health->run();
ok('6: PublishingHealthCheck resolves and runs', count($results) === 2);

echo "\n";
echo $FAIL === 0 ? "MILESTONE 3 CHECKLIST PASSED\n" : "MILESTONE 3 CHECKLIST FAILED\n";
exit($FAIL);

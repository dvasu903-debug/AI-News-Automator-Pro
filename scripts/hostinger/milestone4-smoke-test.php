<?php

declare(strict_types=1);

/**
 * Module 8 Milestone 4 — Hostinger smoke test.
 *
 * Run from the plugin's site root via WP-CLI:
 *   wp eval-file scripts/hostinger/milestone4-smoke-test.php
 *
 * Scope (per the owner's explicit request — do NOT expand beyond this
 * without asking):
 *   1. PublishingServiceProvider loads successfully.
 *   2. GenerateAction, ValidateContentAction, PostProcessAction resolve
 *      from the real, production container.
 *   3. Workflow action registration is correct.
 *   4. DraftSeoRepository persists SEO metadata against real MySQL.
 *   5. Event registration/dispatch succeeds.
 *   6. No PHP warnings, fatals, or uncaught exceptions occur anywhere
 *      in this script.
 *
 * No live AI provider call is made. tests/AI/Fakes/FakeChatProvider is
 * a dev-only fixture that will NOT be present if this deployment ran
 * `composer install --no-dev` (the documented Hostinger deployment
 * step) — so this script defines its own minimal, self-contained fake
 * ChatProviderInterface implementation below, registered into the
 * REAL ProviderRegistryInterface the same way AIServiceProvider
 * registers its real providers. This exercises the complete local
 * orchestration path (real AIManager validation, caching, rate
 * limiting, retry/failover, cost recording, event dispatch — only the
 * network call is faked), which is what the owner's instructions
 * explicitly allow in place of a live call. To ALSO exercise a real
 * provider call instead, configure a real API key in the site's AI
 * settings first and delete/skip the provider-registration block below
 * — optional, not required for this smoke test.
 *
 * All test data created below (a fake AI provider registration that
 * only lives for this process, one publishing profile, one draft post,
 * one research session/claim/citation, one prompt template version,
 * one ana_draft_seo row) is deleted/restored at the end of the script
 * regardless of pass/fail — including the temporary 'ai.defaults.chat'
 * config override, which is restored from a snapshot rather than left
 * pointed at the fake provider.
 */

if (!defined('WP_CLI')) {
    fwrite(STDERR, "This script must be run via WP-CLI: wp eval-file scripts/hostinger/milestone4-smoke-test.php\n");
    exit(1);
}

use AINewsAutomator\AI\Contracts\ChatProviderInterface;
use AINewsAutomator\AI\Contracts\PromptTemplateRepositoryInterface;
use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\AI\Contracts\StructuredOutputProviderInterface;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\ChatResponse;
use AINewsAutomator\AI\DTO\ProviderCapabilities;
use AINewsAutomator\AI\DTO\ProviderHealth;
use AINewsAutomator\AI\DTO\ProviderHealthStatus;
use AINewsAutomator\AI\DTO\StopReason;
use AINewsAutomator\AI\DTO\Usage;
use AINewsAutomator\AI\Prompt\PromptTemplate;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Publishing\Actions\GenerateAction;
use AINewsAutomator\Publishing\Actions\PostProcessAction;
use AINewsAutomator\Publishing\Actions\ValidateContentAction;
use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Events\DraftGeneratedEvent;
use AINewsAutomator\Publishing\Events\PublishingCompletedEvent;
use AINewsAutomator\Publishing\Services\PublishingProfileService;
use AINewsAutomator\Research\Contracts\CitationRepositoryInterface;
use AINewsAutomator\Research\Contracts\ClaimRepositoryInterface;
use AINewsAutomator\Research\Contracts\SessionRepositoryInterface;
use AINewsAutomator\Research\Entities\Citation;
use AINewsAutomator\Research\Entities\Claim;
use AINewsAutomator\Research\Entities\ClaimStatus;
use AINewsAutomator\Research\Entities\ResearchSession;
use AINewsAutomator\Research\Entities\SessionStatus;
use AINewsAutomator\Storage\Entities\EntityDates;
use AINewsAutomator\Workflow\Contracts\ActionRegistryInterface;
use AINewsAutomator\Workflow\DTO\WorkflowRunContext;

/**
 * Minimal, self-contained fake — this file's own replacement for
 * tests/AI/Fakes/FakeChatProvider (a dev-only fixture not guaranteed
 * present on this deployment). Returns one scripted response, makes no
 * network call.
 */
final class Ana_Hostinger_M4_FakeProvider implements ChatProviderInterface, StructuredOutputProviderInterface
{
    public function __construct(private readonly string $responseContent)
    {
    }

    public function id(): string
    {
        return 'harness-fake';
    }

    public function displayName(): string
    {
        return 'Hostinger Smoke Test Fake Provider';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(chat: true, structuredOutput: true);
    }

    public function healthCheck(): ProviderHealth
    {
        return new ProviderHealth($this->id(), ProviderHealthStatus::Healthy);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return new ChatResponse(
            content: $this->responseContent,
            toolCalls: [],
            usage: new Usage(10, 5),
            stopReason: StopReason::EndTurn,
            providerId: $this->id(),
            model: $request->model
        );
    }
}

$FAIL = 0;
$createdPostIds = [];
$createdProfileIds = [];
$createdSessionId = null;
$createdClaimId = null;
$createdTemplateVersion = null;

// Config\OptionBackedConfigRepository stores every override in ONE
// wp_options row (its OPTION_KEY constant, 'ai_news_automator_config_
// overrides') as a nested array — there is no per-key "unset" method on
// ConfigRepositoryInterface, only set(). Snapshotting and restoring the
// whole raw option verbatim is the only way to guarantee this script's
// temporary 'ai.defaults.chat' override doesn't permanently alter this
// site's real AI configuration.
$anaConfigOverridesOption = 'ai_news_automator_config_overrides';
$originalConfigOverrides = get_option($anaConfigOverridesOption, []);

function ana_m4_ok(string $item, bool $pass, string $detail = ''): void
{
    global $FAIL;
    if (!$pass) {
        $FAIL = 1;
    }
    WP_CLI::log(sprintf('[%s] %s%s', $pass ? 'PASS' : 'FAIL', $item, $detail !== '' ? " — $detail" : ''));
}

// Surface every PHP notice/warning as a hard failure for this script's
// duration (requirement 6: "no PHP warnings, fatals, or uncaught
// exceptions"), restored in the finally block below.
$previousErrorHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    global $FAIL;
    $FAIL = 1;
    WP_CLI::log(sprintf('[FAIL] PHP error (level %d): %s in %s:%d', $errno, $errstr, $errfile, $errline));
    return true;
});

try {
    WP_CLI::log('=== 1. PublishingServiceProvider loads successfully ===');
    $plugin = PluginFactory::create(ANA_PRO_FILE);
    $plugin->boot();
    $c = $plugin->container();
    ana_m4_ok('1: plugin boots and container is available', $c !== null);

    WP_CLI::log('=== 2. GenerateAction / ValidateContentAction / PostProcessAction resolve ===');
    $generateAction = $c->get(GenerateAction::class);
    $validateAction = $c->get(ValidateContentAction::class);
    $postProcessAction = $c->get(PostProcessAction::class);
    ana_m4_ok('2: all three actions resolve from the container', $generateAction instanceof GenerateAction
        && $validateAction instanceof ValidateContentAction
        && $postProcessAction instanceof PostProcessAction);

    WP_CLI::log('=== 3. Workflow action registration ===');
    /** @var ActionRegistryInterface $registry */
    $registry = $c->get(ActionRegistryInterface::class);
    foreach (['publishing.generate', 'publishing.validate_content', 'publishing.post_process'] as $type) {
        ana_m4_ok("3: \"$type\" registered", $registry->forType($type) !== null);
    }

    WP_CLI::log('=== 4. Event registration/dispatch ===');
    /** @var EventDispatcherInterface $events */
    $events = $c->get(EventDispatcherInterface::class);
    $metadataFactory = $c->get(EventMetadataFactory::class);
    $eventsSeen = [];
    $events->addListener(DraftGeneratedEvent::class, function (object $e) use (&$eventsSeen): void {
        $eventsSeen[] = $e;
    });
    $events->addListener(PublishingCompletedEvent::class, function (object $e) use (&$eventsSeen): void {
        $eventsSeen[] = $e;
    });
    $events->dispatch(new DraftGeneratedEvent($metadataFactory->create('Publishing', []), postId: 0, researchSessionId: 0));
    ana_m4_ok('4: DraftGeneratedEvent listener invoked', count($eventsSeen) === 1);

    WP_CLI::log('=== 5. Full local-orchestration-path exercise (stub provider, no live AI call) ===');

    /** @var ProviderRegistryInterface $providerRegistry */
    $providerRegistry = $c->get(ProviderRegistryInterface::class);
    $providerRegistry->register(new Ana_Hostinger_M4_FakeProvider(json_encode([
        'title' => '<b>Hostinger</b> Smoke Test Title',
        'body'  => '<p>Real content.</p><script>alert(1)</script>',
    ])));

    /** @var ConfigRepositoryInterface $config */
    $config = $c->get(ConfigRepositoryInterface::class);
    $config->set('ai.defaults.chat', 'harness-fake');

    // PromptTemplateRepositoryInterface has no delete() (versions are
    // write-once by design) — this row is cleaned up directly via wpdb
    // in the finally block below, the same narrowly-scoped exception
    // already used there for the claim/citation rows this script seeds.
    // Must satisfy PromptTemplate::isValidSemver() (MAJOR.MINOR.PATCH,
    // digits only) — the timestamp as the patch number keeps this
    // unique across repeated runs without colliding with a real
    // administrator-authored version.
    $createdTemplateVersion = '0.0.' . time();

    /** @var PromptTemplateRepositoryInterface $templates */
    $templates = $c->get(PromptTemplateRepositoryInterface::class);
    $templates->saveNewVersion(new PromptTemplate(
        null,
        'publishing.article_generation',
        $createdTemplateVersion,
        'news',
        'You are a professional, trustworthy news writer. (Hostinger smoke test template.)',
        [],
        EntityDates::now()
    ));

    /** @var SessionRepositoryInterface $sessions */
    $sessions = $c->get(SessionRepositoryInterface::class);
    $createdSessionId = $sessions->save(new ResearchSession(
        null,
        'hostinger-smoke-m4-' . time(),
        'Hostinger Smoke Test Topic',
        'news',
        SessionStatus::Completed,
        null,
        0.9,
        EntityDates::now(),
        EntityDates::now(),
        EntityDates::now()
    ));

    /** @var ClaimRepositoryInterface $claims */
    $claims = $c->get(ClaimRepositoryInterface::class);
    $createdClaimId = $claims->record(new Claim(null, $createdSessionId, 'The Hostinger smoke test proves the pipeline works.', 0.9, ClaimStatus::Supported, EntityDates::now()));

    /** @var CitationRepositoryInterface $citations */
    $citations = $c->get(CitationRepositoryInterface::class);
    $citations->record(new Citation(null, $createdClaimId, 1, '<b>Untrusted</b> Source & Co, 2026.', EntityDates::now()));

    $profileService = $c->get(PublishingProfileService::class);
    $profile = $profileService->create(new PublishingProfile(
        null,
        'hostinger-m4-smoke-' . time(),
        'Hostinger M4 Smoke',
        'standard_publish',
        'news',
        'manual',
        ['ai_disclosure_acknowledged' => true, 'min_citation_count' => 1, 'min_confidence' => 0.5]
    ));
    $createdProfileIds[] = $profile->id();

    $generateResult = $generateAction->execute(new WorkflowRunContext(0, 'generate', 'hostinger-smoke', ['research_session_id' => $createdSessionId], []));
    ana_m4_ok('5a: GenerateAction succeeds against real production stack', $generateResult->isSuccess(), $generateResult->error ?? '');

    $postId = (int) ($generateResult->output['post_id'] ?? 0);
    if ($postId > 0) {
        $createdPostIds[] = $postId;
    }

    $createdPost = $postId > 0 ? get_post($postId) : null;
    ana_m4_ok('5b: draft post created', $createdPost !== null);
    ana_m4_ok(
        '5c: wp_kses_post()/esc_html() trust boundary holds (script stripped, citation escaped)',
        $createdPost !== null
            && !str_contains((string) $createdPost->post_content, '<script')
            && str_contains((string) $createdPost->post_content, '&lt;b&gt;Untrusted&lt;/b&gt;')
    );

    $validateResult = $validateAction->execute(new WorkflowRunContext(0, 'validate_content', 'hostinger-smoke', ['post_id' => $postId, 'profile_id' => $profile->id()], []));
    ana_m4_ok('5d: ValidateContentAction succeeds', $validateResult->isSuccess(), $validateResult->error ?? '');

    $postProcessResult = $postProcessAction->execute(new WorkflowRunContext(0, 'post_process', 'hostinger-smoke', ['post_id' => $postId], []));
    ana_m4_ok('5e: PostProcessAction succeeds', $postProcessResult->isSuccess(), $postProcessResult->error ?? '');

    WP_CLI::log('=== 6. DraftSeoRepository persists SEO metadata ===');
    /** @var DraftSeoRepositoryInterface $seoRepository */
    $seoRepository = $c->get(DraftSeoRepositoryInterface::class);
    $seo = $postId > 0 ? $seoRepository->findByPostId($postId) : null;
    ana_m4_ok('6a: ana_draft_seo row persisted against real MySQL', $seo !== null);
    ana_m4_ok('6b: meta_title derived and non-empty', $seo !== null && ($seo->metaTitle ?? '') !== '');
    ana_m4_ok('6c: robots_directives default applied', $seo !== null && $seo->robotsDirectives === 'index,follow');
} catch (\Throwable $e) {
    $FAIL = 1;
    WP_CLI::log(sprintf('[FAIL] Uncaught %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
} finally {
    restore_error_handler();

    global $wpdb;

    // Restore the real site's AI config overrides verbatim — this is a
    // real, persisted wp_options value, not test-double state, so
    // leaving 'ai.defaults.chat' pointed at 'harness-fake' would break
    // real AI functionality on this site after the script exits. Must
    // run before anything else in cleanup.
    update_option($anaConfigOverridesOption, $originalConfigOverrides, false);

    if ($createdClaimId !== null) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_research_citations WHERE claim_id = %d", $createdClaimId));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_research_claims WHERE id = %d", $createdClaimId));
    }
    if ($createdSessionId !== null) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_research_sessions WHERE id = %d", $createdSessionId));
    }
    if ($createdTemplateVersion !== null) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ana_prompt_templates WHERE name = %s AND version = %s",
            'publishing.article_generation',
            $createdTemplateVersion
        ));
    }
    foreach ($createdProfileIds as $profileId) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_publishing_profiles WHERE id = %d", $profileId));
    }
    foreach ($createdPostIds as $postId) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_draft_seo WHERE post_id = %d", $postId));
        wp_delete_post($postId, true);
    }

    WP_CLI::log('Test data cleaned up: ' . count($createdPostIds) . ' post(s), ' . count($createdProfileIds) . ' profile(s), research session ' . ($createdSessionId ?? 'none') . ', prompt template version ' . ($createdTemplateVersion ?? 'none') . '.');
}

WP_CLI::log('');
if ($FAIL === 0) {
    WP_CLI::success('MILESTONE 4 HOSTINGER SMOKE TEST PASSED');
} else {
    WP_CLI::error('MILESTONE 4 HOSTINGER SMOKE TEST FAILED — see [FAIL] lines above.');
}

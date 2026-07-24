<?php

declare(strict_types=1);

/**
 * Module 8 Milestone 4 runtime checklist: the AI-generation pipeline —
 * GenerateAction (real AIManager orchestration against a stubbed
 * provider, since a real network call is out of scope for this local
 * harness), the wp_kses_post()/esc_html() trust boundary (ADR-0019),
 * ValidateContentAction (merging DefaultEditorialPolicy +
 * ResearchEditorialPolicy violations), and PostProcessAction populating
 * ana_draft_seo. See
 * docs/verification/2026-07-23-module-8-milestone-4-runtime-verification.md
 * for the pass this codifies.
 *
 * Only the AI provider's network call is faked (AI module's own
 * FakeChatProvider, registered into the REAL ProviderRegistryInterface
 * via the same registration mechanism AIServiceProvider itself uses) —
 * every other collaborator (AIManager, the real Research repositories,
 * a real ResearchSession/Claim/Citation persisted to MariaDB, the real
 * DraftSeoRepository) is genuine.
 */

require __DIR__ . '/../harness-bootstrap.php';

use AINewsAutomator\AI\Contracts\PromptTemplateRepositoryInterface;
use AINewsAutomator\AI\Contracts\ProviderRegistryInterface;
use AINewsAutomator\AI\Prompt\PromptTemplate;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
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
use AINewsAutomator\Tests\AI\Fakes\FakeChatProvider;
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
$c = $plugin->container();

echo "=== 0. Fixtures: stub AI provider, prompt template, research session ===\n";

/** @var ProviderRegistryInterface $providerRegistry */
$providerRegistry = $c->get(ProviderRegistryInterface::class);
$fakeProvider = new FakeChatProvider('harness-fake');
$fakeProvider->willReturn(FakeChatProvider::successResponse('harness-fake', json_encode([
    'title' => '<b>Breaking</b> Harness News',
    'body'  => '<p>Real content.</p><script>alert(1)</script>',
])));
$providerRegistry->register($fakeProvider);

/** @var ConfigRepositoryInterface $config */
$config = $c->get(ConfigRepositoryInterface::class);
$config->set('ai.defaults.chat', 'harness-fake');

/** @var PromptTemplateRepositoryInterface $templates */
$templates = $c->get(PromptTemplateRepositoryInterface::class);
$templates->saveNewVersion(new PromptTemplate(
    null,
    'publishing.article_generation',
    '1.0.0',
    'news',
    'You are a professional, trustworthy news writer.',
    [],
    EntityDates::now()
));

/** @var SessionRepositoryInterface $sessions */
$sessions = $c->get(SessionRepositoryInterface::class);
$sessionId = $sessions->save(new ResearchSession(
    null,
    'harness-corr-m4',
    'Harness Topic',
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
$claimId = $claims->record(new Claim(null, $sessionId, 'The harness proves the pipeline works.', 0.9, ClaimStatus::Supported, EntityDates::now()));

/** @var CitationRepositoryInterface $citations */
$citations = $c->get(CitationRepositoryInterface::class);
$citations->record(new Citation(null, $claimId, 1, '<b>Untrusted</b> Source & Co, 2026.', EntityDates::now()));

$profileService = $c->get(PublishingProfileService::class);
$wpdb->query('DELETE FROM wp_ana_publishing_profiles');
$profile = $profileService->create(new PublishingProfile(
    null,
    'm4-smoke',
    'M4 Smoke',
    'standard_publish',
    'news',
    'manual',
    ['ai_disclosure_acknowledged' => true, 'min_citation_count' => 1, 'min_confidence' => 0.5]
));
$strictProfile = $profileService->create(new PublishingProfile(
    null,
    'm4-strict',
    'M4 Strict',
    'standard_publish',
    'news',
    'manual',
    ['ai_disclosure_acknowledged' => true, 'min_citation_count' => 99]
));

$registry = $c->get(ActionRegistryInterface::class);

echo "\n=== 1. Actions registered ===\n";
foreach (['publishing.generate', 'publishing.validate_content', 'publishing.post_process'] as $type) {
    ok("1: \"$type\" registered", $registry->forType($type) !== null);
}

echo "\n=== 2. GenerateAction: real AIManager orchestration + trust boundary ===\n";
$generateAction = $registry->forType('publishing.generate');
$generateResult = $generateAction->execute(new WorkflowRunContext(1, 'generate', 'corr-m4', ['research_session_id' => $sessionId], []));

ok('2a: generate succeeds', $generateResult->isSuccess(), $generateResult->error ?? '');
$postId = (int) ($generateResult->output['post_id'] ?? 0);
ok('2b: draft post created', $postId > 0);

$createdPost = $GLOBALS['__posts'][$postId] ?? null;
ok('2c: title tags stripped', ($createdPost['post_title'] ?? '') === 'Breaking Harness News');
ok('2d: script tag stripped from body (wp_kses_post)', !str_contains((string) ($createdPost['post_content'] ?? ''), '<script'));
ok('2e: safe markup preserved', str_contains((string) ($createdPost['post_content'] ?? ''), '<p>Real content.</p>'));
ok('2f: citation text escaped, not raw, when appended', str_contains((string) ($createdPost['post_content'] ?? ''), '&lt;b&gt;Untrusted&lt;/b&gt;'));
ok('2g: raw unescaped citation markup is NOT present', !str_contains((string) ($createdPost['post_content'] ?? ''), '<b>Untrusted</b>'));
ok('2h: research session id postmeta recorded', (int) ($GLOBALS['__postmeta'][$postId]['_ana_research_session_id'] ?? 0) === $sessionId);

echo "\n=== 3. ValidateContentAction: merges DefaultEditorialPolicy + ResearchEditorialPolicy ===\n";
$validateAction = $registry->forType('publishing.validate_content');

$passResult = $validateAction->execute(new WorkflowRunContext(2, 'validate_content', 'corr-m4', ['post_id' => $postId, 'profile_id' => $profile->id()], []));
ok('3a: passes with sufficient citations/confidence', $passResult->isSuccess(), $passResult->error ?? '');

$failResult = $validateAction->execute(new WorkflowRunContext(3, 'validate_content', 'corr-m4', ['post_id' => $postId, 'profile_id' => $strictProfile->id()], []));
ok('3b: fails against a stricter min_citation_count profile', $failResult->isFailure());

echo "\n=== 4. ValidateContentAction reads post_id via priorOutput() when absent from step config ===\n";
$priorOutputResult = $validateAction->execute(new WorkflowRunContext(
    4,
    'validate_content',
    'corr-m4',
    ['profile_id' => $profile->id()],
    ['generate' => ['post_id' => $postId]]
));
ok('4: priorOutput() resolves post_id from the "generate" step', $priorOutputResult->isSuccess(), $priorOutputResult->error ?? '');

echo "\n=== 5. PostProcessAction: populates ana_draft_seo ===\n";
$postProcessAction = $registry->forType('publishing.post_process');
$postProcessResult = $postProcessAction->execute(new WorkflowRunContext(5, 'post_process', 'corr-m4', ['post_id' => $postId], []));
ok('5a: post_process succeeds', $postProcessResult->isSuccess(), $postProcessResult->error ?? '');

/** @var DraftSeoRepositoryInterface $seoRepository */
$seoRepository = $c->get(DraftSeoRepositoryInterface::class);
$seo = $seoRepository->findByPostId($postId);
ok('5b: ana_draft_seo row persisted', $seo !== null);
ok('5c: meta_title derived', $seo !== null && $seo->metaTitle !== null && $seo->metaTitle !== '');
ok('5d: meta_description has no markup', $seo !== null && !str_contains((string) $seo->metaDescription, '<'));
ok('5e: robots_directives default applied', $seo !== null && $seo->robotsDirectives === 'index,follow');

echo "\n";
echo $FAIL === 0 ? "MILESTONE 4 CHECKLIST PASSED\n" : "MILESTONE 4 CHECKLIST FAILED\n";
exit($FAIL);

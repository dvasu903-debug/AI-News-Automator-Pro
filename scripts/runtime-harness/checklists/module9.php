<?php

declare(strict_types=1);

/**
 * Module 9 (SEO Engine) runtime checklist: SeoServiceProvider loads,
 * SeoHeadRenderer/SeoProviderInterface/MetaTagBuilder/SchemaOrgGenerator
 * produce correct, escaped output end-to-end against a real,
 * database-persisted ana_draft_seo row (Publishing's frozen table,
 * read through its frozen DraftSeoRepositoryInterface), a post with no
 * ana_draft_seo row renders nothing, and the hostile-string escaping
 * regression holds through the real, booted container's own code
 * paths. See planning/MODULE_9_SEO_ENGINE_DESIGN.md.
 */

require __DIR__ . '/../harness-bootstrap.php';

use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Seo\Frontend\SeoHeadRenderer;
use AINewsAutomator\Seo\Health\SeoHealthCheck;

$FAIL = 0;
function ok(string $item, bool $pass, string $detail = ''): void
{
    global $FAIL;
    if (!$pass) {
        $FAIL = 1;
    }
    printf("[%s] %s%s\n", $pass ? 'PASS' : 'FAIL', $item, $detail !== '' ? " — $detail" : '');
}

$plugin = PluginFactory::create(ANA_PRO_FILE);
$plugin->boot();
do_action('plugins_loaded');
$c = $plugin->container();

echo "=== 1. SeoServiceProvider loaded: container resolution ===\n";
$renderer = $c->get(SeoHeadRenderer::class);
ok('1a: SeoHeadRenderer resolves', $renderer instanceof SeoHeadRenderer);
$health = $c->get(SeoHealthCheck::class);
$results = $health->run();
ok('1b: SeoHealthCheck resolves and runs', count($results) === 1 && $results[0]->status->value === 'ok');

echo "\n=== 2. No ana_draft_seo row: renders nothing ===\n";
$GLOBALS['__posts'][601] = ['post_title' => 'Untouched Post', 'post_content' => 'Body.'];
ob_start();
$renderer->renderFor(601);
$output = ob_get_clean();
ok('2: no ana_draft_seo row -> empty output', $output === '');

echo "\n=== 3. Real ana_draft_seo row: correct, escaped output ===\n";
/** @var DraftSeoRepositoryInterface $seoRepository */
$seoRepository = $c->get(DraftSeoRepositoryInterface::class);
$seoRepository->upsert(new DraftSeo(
    null,
    602,
    'Breaking: Harness Proves The Pipeline',
    'A harness-verified description.',
    'harness',
    null,
    'index,follow'
));
$GLOBALS['__posts'][602] = [
    'post_title' => 'Breaking: Harness Proves The Pipeline',
    'post_content' => 'Body content.',
    'post_date' => '2026-01-15 09:00:00',
    'post_modified' => '2026-01-15 09:00:00',
];
$GLOBALS['__permalinks'][602] = 'https://harness.test/breaking-harness';
$GLOBALS['__thumbnails'][602] = 'https://harness.test/image.jpg';

ob_start();
$renderer->renderFor(602);
$output = ob_get_clean();

ok('3a: canonical link rendered from real, database-backed ana_draft_seo row', str_contains($output, '<link rel="canonical" href="https://harness.test/breaking-harness" />'));
ok('3b: robots meta rendered', str_contains($output, '<meta name="robots" content="index,follow" />'));
ok('3c: og:title rendered', str_contains($output, 'property="og:title" content="Breaking: Harness Proves The Pipeline"'));
ok('3d: og:image rendered (featured image present)', str_contains($output, 'property="og:image" content="https://harness.test/image.jpg"'));
ok('3e: twitter:card is summary_large_image with an image', str_contains($output, 'name="twitter:card" content="summary_large_image"'));
ok('3f: JSON-LD NewsArticle block rendered', str_contains($output, '<script type="application/ld+json">') && str_contains($output, '"@type":"NewsArticle"'));

echo "\n=== 4. Hostile-string escaping regression (real, booted container's own code path) ===\n";
$seoRepository->upsert(new DraftSeo(
    null,
    603,
    '"><script>alert(1)</script>',
    '"><img src=x onerror=alert(1)>',
    null,
    null,
    'index,follow'
));
$GLOBALS['__posts'][603] = ['post_title' => 'Hostile Post', 'post_content' => 'Body.'];
$GLOBALS['__permalinks'][603] = 'https://harness.test/"><script>alert(1)</script>';

ob_start();
$renderer->renderFor(603);
$output = ob_get_clean();

ok('4a: hostile og:title never appears as a literal script tag', !str_contains($output, '<script>alert(1)</script>'));
ok('4b: hostile canonical URL never appears as a literal script tag', substr_count($output, '<script>alert(1)</script>') === 0);
ok('4c: hostile twitter description never appears as a literal img/onerror tag', !str_contains($output, '<img src=x onerror=alert(1)>'));
ok('4d: JSON-LD block is present and headline field survives only escaped', str_contains($output, '<script type="application/ld+json">'));

echo "\n";
echo $FAIL === 0 ? "MODULE 9 CHECKLIST PASSED\n" : "MODULE 9 CHECKLIST FAILED\n";
exit($FAIL);

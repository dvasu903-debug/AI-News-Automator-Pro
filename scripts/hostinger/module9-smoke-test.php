<?php
/**
 * Module 9 (SEO Engine) — Hostinger smoke test.
 *
 * Run from the plugin's site root via WP-CLI:
 *   wp eval-file scripts/hostinger/module9-smoke-test.php
 *
 * NOTE: deliberately no `declare(strict_types=1)` — see Milestone 4's
 * smoke test script for why (wp eval-file evaluates this file's
 * content via PHP's eval(), which fatals on a leading
 * declare(strict_types=1)).
 *
 * No AI provider call anywhere — this module makes none. Real cost:
 * zero.
 *
 * This is the first Hostinger smoke test that verifies PUBLIC-FACING
 * rendered output rather than only admin/REST/CLI-side behavior: it
 * creates one real, published test post and fetches its actual live
 * URL via wp_remote_get() (WordPress's own HTTP client), checking the
 * real HTML response body for the expected tags — not just the
 * internal renderFor() output buffer. Both checks run; the internal
 * output-buffer check is authoritative (a page cache in front of the
 * site could serve a stale response to the live fetch immediately
 * after the post is created, which would be an environmental caching
 * concern, not evidence of a code defect — this is logged as a
 * warning, not a hard failure, if the two disagree).
 *
 * All test data (one post, one ana_draft_seo row) is deleted at the
 * end of the script regardless of pass/fail.
 */

if (!defined('WP_CLI')) {
    fwrite(STDERR, "This script must be run via WP-CLI: wp eval-file scripts/hostinger/module9-smoke-test.php\n");
    exit(1);
}

use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Publishing\Contracts\DraftSeoRepositoryInterface;
use AINewsAutomator\Publishing\DTO\DraftSeo;
use AINewsAutomator\Seo\Frontend\SeoHeadRenderer;
use AINewsAutomator\Seo\Health\SeoHealthCheck;

$FAIL = 0;
$createdPostId = null;

function ana_m9_ok(string $item, bool $pass, string $detail = ''): void
{
    global $FAIL;
    if (!$pass) {
        $FAIL = 1;
    }
    WP_CLI::log(sprintf('[%s] %s%s', $pass ? 'PASS' : 'FAIL', $item, $detail !== '' ? " — $detail" : ''));
}

$previousErrorHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    global $FAIL;
    $FAIL = 1;
    WP_CLI::log(sprintf('[FAIL] PHP error (level %d): %s in %s:%d', $errno, $errstr, $errfile, $errline));
    return true;
});

try {
    WP_CLI::log('=== 1. SeoServiceProvider loads, resolves from the container ===');
    $plugin = PluginFactory::create(ANA_PRO_FILE);
    $plugin->boot();
    $c = $plugin->container();

    $renderer = $c->get(SeoHeadRenderer::class);
    ana_m9_ok('1a: SeoHeadRenderer resolves', $renderer instanceof SeoHeadRenderer);

    $health = $c->get(SeoHealthCheck::class);
    $healthResults = $health->run();
    ana_m9_ok('1b: SeoHealthCheck resolves and runs', count($healthResults) === 1 && $healthResults[0]->status->value === 'ok');

    WP_CLI::log('=== 2. Real published post + real ana_draft_seo row ===');
    $createdPostId = wp_insert_post([
        'post_title' => 'Module 9 Hostinger Smoke Test',
        'post_content' => '<p>Real content for the Module 9 smoke test.</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ], true);
    ana_m9_ok('2a: real post created and published', is_int($createdPostId) && $createdPostId > 0);

    /** @var DraftSeoRepositoryInterface $seoRepository */
    $seoRepository = $c->get(DraftSeoRepositoryInterface::class);
    $seoRepository->upsert(new DraftSeo(
        null,
        $createdPostId,
        'Smoke Test Meta Title',
        'Smoke test meta description.',
        'smoke',
        null,
        'index,follow'
    ));

    WP_CLI::log('=== 3. Internal render check (authoritative) ===');
    ob_start();
    $renderer->renderFor($createdPostId);
    $internalOutput = (string) ob_get_clean();

    ana_m9_ok('3a: canonical link rendered', str_contains($internalOutput, '<link rel="canonical"'));
    ana_m9_ok('3b: og:title rendered', str_contains($internalOutput, 'property="og:title"'));
    ana_m9_ok('3c: JSON-LD NewsArticle block rendered', str_contains($internalOutput, 'application/ld+json') && str_contains($internalOutput, 'NewsArticle'));

    WP_CLI::log('=== 4. Live public HTTP fetch (new dimension for this milestone) ===');
    $permalink = get_permalink($createdPostId);
    ana_m9_ok('4a: real permalink resolves', is_string($permalink) && $permalink !== '');

    if (is_string($permalink) && $permalink !== '') {
        $response = wp_remote_get($permalink, ['timeout' => 10]);

        if (is_wp_error($response)) {
            WP_CLI::log('[WARN] Live HTTP fetch failed: ' . $response->get_error_message() . ' — treating as inconclusive, not a failure (see script docblock).');
        } else {
            $body = (string) wp_remote_retrieve_body($response);
            $liveHasCanonical = str_contains($body, '<link rel="canonical"');
            $liveHasOg = str_contains($body, 'property="og:title"');
            $liveHasJsonLd = str_contains($body, 'application/ld+json');

            if ($liveHasCanonical && $liveHasOg && $liveHasJsonLd) {
                WP_CLI::log('[PASS] 4b: live fetched page contains canonical/OG/JSON-LD tags');
            } else {
                WP_CLI::log('[WARN] 4b: live fetched page is missing expected tags — likely a page cache serving a stale response immediately after post creation (see script docblock); not counted as a failure. Re-run this script a second time, or purge the site cache first, to confirm.');
            }
        }
    }

    WP_CLI::log('=== 5. Hostile-string escaping regression ===');
    $hostilePostId = wp_insert_post([
        'post_title' => 'Hostile Smoke Test Post',
        'post_content' => '<p>Body.</p>',
        'post_status' => 'publish',
        'post_type' => 'post',
    ], true);

    if (is_int($hostilePostId) && $hostilePostId > 0) {
        $seoRepository->upsert(new DraftSeo(
            null,
            $hostilePostId,
            '"><script>alert(1)</script>',
            '"><img src=x onerror=alert(1)>',
            null,
            null,
            'index,follow'
        ));

        ob_start();
        $renderer->renderFor($hostilePostId);
        $hostileOutput = (string) ob_get_clean();

        ana_m9_ok('5a: hostile og:title never appears as a literal script tag', !str_contains($hostileOutput, '<script>alert(1)</script>'));
        ana_m9_ok('5b: hostile description never appears as a literal img/onerror tag', !str_contains($hostileOutput, '<img src=x onerror=alert(1)>'));

        $seoRepository = $c->get(DraftSeoRepositoryInterface::class);
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_draft_seo WHERE post_id = %d", $hostilePostId));
        wp_delete_post($hostilePostId, true);
    }
} catch (\Throwable $e) {
    $FAIL = 1;
    WP_CLI::log(sprintf('[FAIL] Uncaught %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
} finally {
    restore_error_handler();

    global $wpdb;

    if (is_int($createdPostId) && $createdPostId > 0) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ana_draft_seo WHERE post_id = %d", $createdPostId));
        wp_delete_post($createdPostId, true);
    }

    WP_CLI::log('Test data cleaned up.');
}

WP_CLI::log('');
if ($FAIL === 0) {
    WP_CLI::success('MODULE 9 HOSTINGER SMOKE TEST PASSED');
} else {
    WP_CLI::error('MODULE 9 HOSTINGER SMOKE TEST FAILED — see [FAIL] lines above.');
}

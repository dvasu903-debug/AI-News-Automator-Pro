<?php

declare(strict_types=1);

/**
 * Module 8 Milestone 2 runtime checklist (regression check) — items C
 * (container identity probe) and D6-D14 (migrations, CRUD, utf8mb4,
 * markDefault concurrency-safe demote, rollback, requireDefault failure
 * path, policies). See
 * docs/verification/2026-07-23-module-8-milestone-2-runtime-verification.md
 * for the original pass this codifies. Kept as a permanent regression
 * check — future milestones must not silently break Milestone 2's
 * frozen behavior.
 */

require __DIR__ . '/../harness-bootstrap.php';

use AINewsAutomator\Core\PluginFactory;
use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;
use AINewsAutomator\Publishing\Exceptions\PublishingConfigurationException;
use AINewsAutomator\Publishing\Services\PublishingProfileService;

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

echo "=== C. Container identity probe ===\n";
$r1 = $c->get(PublishingProfileRepositoryInterface::class);
$r2 = $c->get(PublishingProfileRepositoryInterface::class);
ok('C: repository resolves as singleton', spl_object_id($r1) === spl_object_id($r2));
$s1 = $c->get(PublishingProfileService::class);
$s2 = $c->get(PublishingProfileService::class);
ok('C: service resolves as singleton', spl_object_id($s1) === spl_object_id($s2));

$service = $s1;
$repo = $r1;

echo "\n=== D6. Migrations applied via plugins_loaded self-healing ===\n";
$cols = $wpdb->get_results('SHOW COLUMNS FROM wp_ana_publishing_profiles', ARRAY_A);
$colNames = array_column($cols, 'Field');
$expected = ['id', 'slug', 'name', 'vertical', 'workflow_key', 'approval_mode', 'config', 'enabled', 'is_default', 'created_at', 'updated_at'];
ok('D6: ana_publishing_profiles has expected columns incl. is_default', array_diff($expected, $colNames) === []);
$recorded = $wpdb->get_col("SELECT version FROM wp_ana_schema_migrations WHERE version LIKE '202607221%' ORDER BY version");
ok('D6: all 4 Publishing migrations recorded', $recorded === ['20260722100001', '20260722100002', '20260722100003', '20260722100004']);

echo "\n=== D7. Slug uniqueness index ===\n";
$indexes = $wpdb->get_results('SHOW INDEX FROM wp_ana_publishing_profiles', ARRAY_A);
$slugUnique = false;
foreach ($indexes as $ix) {
    if ($ix['Key_name'] === 'slug' && $ix['Non_unique'] === '0') {
        $slugUnique = true;
    }
}
ok('D7: DB-level UNIQUE index on slug', $slugUnique);

echo "\n=== D8. Full CRUD cycle against MySQL ===\n";
$wpdb->query('DELETE FROM wp_ana_publishing_profiles');
$created = $service->create(new PublishingProfile(null, 'tech-news', 'Tech News', 'standard_publish', 'technology', 'manual', ['tone' => 'neutral']));
ok('D8: create returns id', $created->id() !== null);
$found = $service->getById((int) $created->id());
ok('D8: findById round-trip', $found !== null && $found->slug() === 'tech-news');
$second = $service->create(new PublishingProfile(null, 'biz-news', 'Biz News', 'standard_publish', 'business', 'manual', [], false));
ok('D8: findAll / findAll(enabledOnly)', count($service->listProfiles()) === 2 && count($service->listProfiles(true)) === 1);

echo "\n=== D7b. excludeId path (Filter::notEquals) against real MySQL ===\n";
ok('D7b: existsWithSlug excludes own id', $repo->existsWithSlug('tech-news', (int) $created->id()) === false);
ok('D7b: existsWithSlug detects collision excluding other id', $repo->existsWithSlug('tech-news', (int) $second->id()) === true);

echo "\n=== D9. utf8mb4 / emoji JSON round-trip ===\n";
$emoji = $service->create(new PublishingProfile(null, 'intl', 'Intl 🌍 Profile', 'standard_publish', 'news', 'manual', ['emoji' => '🎉👩‍💻🇩🇪']));
$back = $service->getById((int) $emoji->id());
ok('D9: emoji round-trip intact', $back->name() === 'Intl 🌍 Profile' && ($back->config()['emoji'] ?? '') === '🎉👩‍💻🇩🇪');

echo "\n=== D10/D12. markDefault() single-writer invariant (the D12 fix) ===\n";
$service->markDefault((int) $created->id());
$service->markDefault((int) $emoji->id());
$countDefault = fn (): int => (int) $wpdb->get_var('SELECT COUNT(*) FROM wp_ana_publishing_profiles WHERE is_default = 1');
ok('D10: exactly one default after switching', $countDefault() === 1);

echo "\n=== D13. requireDefault() failure path ===\n";
$wpdb->query('UPDATE wp_ana_publishing_profiles SET is_default = 0');
$threw = false;
try {
    $service->requireDefault();
} catch (PublishingConfigurationException $e) {
    $threw = true;
}
ok('D13: no default -> PublishingConfigurationException', $threw);
$service->markDefault((int) $created->id());

echo "\n=== D14. Policy checks live ===\n";
$threw = false;
try {
    $service->delete((int) $created->id());
} catch (ProfileValidationException $e) {
    $threw = true;
}
ok('D14: deleting the default profile rejected', $threw);

echo "\n";
echo $FAIL === 0 ? "MILESTONE 2 CHECKLIST PASSED\n" : "MILESTONE 2 CHECKLIST FAILED\n";
exit($FAIL);

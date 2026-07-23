<?php

declare(strict_types=1);

/**
 * Test bootstrap for isolated (non-WordPress-integration) unit tests.
 *
 * These stub the handful of WordPress functions that Core module code
 * calls directly, so Container/Logger/etc. can be unit tested without
 * spinning up a full WordPress install. This is deliberately NOT a
 * replacement for WordPress integration tests (e.g. via wp-env +
 * WP_UnitTestCase) — those should be added under tests/Integration/
 * once modules that actually touch the database (Storage, Queue) exist.
 * For pure PHP logic like the Container, these stubs are sufficient and
 * much faster to run.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['__ana_test_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['__ana_test_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        unset($GLOBALS['__ana_test_options'][$key]);
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data): string|false
    {
        return json_encode($data);
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public mixed $data = ''
        ) {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string
    {
        return $GLOBALS['__ana_test_env'] ?? 'production';
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return rtrim(dirname($file), '/') . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

// --- Additional stubs for Security module unit tests ---

if (!defined('ANA_TEST_SALT')) {
    // Deterministic base secret so KeyProvider is reproducible in tests.
    define('ANA_TEST_SALT', 'unit-test-fixed-salt-value-not-for-production-use-0123456789abcdef');
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return trim($email);
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url): array|false
    {
        return parse_url($url);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

// Simple in-memory transient store for rate limiter / threat detector tests.
if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        $store = $GLOBALS['__ana_test_transients'][$key] ?? null;
        if ($store === null) {
            return false;
        }
        if ($store['expires'] !== 0 && $store['expires'] < time()) {
            unset($GLOBALS['__ana_test_transients'][$key]);
            return false;
        }
        return $store['value'];
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $ttl = 0): bool
    {
        $GLOBALS['__ana_test_transients'][$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['__ana_test_transients'][$key]);
        return true;
    }
}

if (!function_exists('user_can')) {
    /**
     * Stub for the raw WP capability check (distinct from
     * current_user_can(), which checks the CURRENT user — user_can()
     * checks an arbitrary user id, which is what AuthorizationContext-
     * consuming policies like ResearchAbilityPolicy use). Tests configure
     * per-user capability sets via $GLOBALS['__ana_test_user_caps'][$userId] = ['cap1', 'cap2'].
     */
    function user_can(int $userId, string $capability): bool
    {
        $caps = $GLOBALS['__ana_test_user_caps'][$userId] ?? [];
        return in_array($capability, $caps, true);
    }
}

// --- Additional stubs for Workflow module unit tests ---

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('ARRAY_A')) {
    // wpdb fetch-mode constants — normally defined by real WordPress core
    // (wp-includes/wp-db.php), which this PHPUnit harness deliberately
    // never loads (tests use FakeWpdb instead). Connection.php and
    // SchemaInspector.php reference these unqualified inside a namespace;
    // PHP falls back to the global namespace for unqualified constants,
    // but only if something has actually defined them globally — which,
    // outside a real WP runtime, is exactly what these four lines do.
    // Values match WP core's own definitions exactly (the constant's
    // value is literally its own name).
    define('OBJECT', 'OBJECT');
    define('OBJECT_K', 'OBJECT_K');
    define('ARRAY_A', 'ARRAY_A');
    define('ARRAY_N', 'ARRAY_N');
}

if (!function_exists('wp_mail')) {
    function wp_mail(string $to, string $subject, string $message): bool
    {
        $GLOBALS['__ana_test_sent_mail'][] = compact('to', 'subject', 'message');
        return $GLOBALS['__ana_test_wp_mail_result'] ?? true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        return $GLOBALS['__ana_test_cron'][$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
    {
        $GLOBALS['__ana_test_cron'][$hook] = $timestamp;
        return true;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event(int $timestamp, string $hook): bool
    {
        unset($GLOBALS['__ana_test_cron'][$hook]);
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return $GLOBALS['__ana_test_current_user_id'] ?? 0;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

// Minimal in-memory post store for Publishing module tests — same
// pattern as the transient/cron stubs above. Only the operations
// DraftRepository actually calls are modeled; not a general WP_Post
// simulation.
if (!function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed> $postarr
     */
    function wp_update_post(array $postarr): int
    {
        $id = (int) ($postarr['ID'] ?? 0);
        $GLOBALS['__ana_test_posts'][$id] = array_merge(
            $GLOBALS['__ana_test_posts'][$id] ?? [],
            $postarr
        );
        return $id;
    }
}

if (!function_exists('wp_delete_post')) {
    function wp_delete_post(int $postId, bool $forceDelete = false): mixed
    {
        unset($GLOBALS['__ana_test_posts'][$postId], $GLOBALS['__ana_test_postmeta'][$postId]);
        return true;
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value): bool
    {
        $GLOBALS['__ana_test_postmeta'][$postId][$key] = $value;
        return true;
    }
}

// Minimal WP_Post + get_post()/wp_strip_all_tags() additions for
// Publishing Milestone 3 tests (PublishingService/DefaultEditorialPolicy
// need to read a post's content, not just write one). Tests seed
// $GLOBALS['__ana_test_posts'][$id] directly with whatever fields the
// scenario needs; get_post() hydrates a WP_Post from that array.
if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_status = 'draft';
        public string $post_date = '';

        /**
         * @param array<string, mixed> $data
         */
        public function __construct(array $data)
        {
            $this->ID = (int) ($data['ID'] ?? 0);
            $this->post_title = (string) ($data['post_title'] ?? '');
            $this->post_content = (string) ($data['post_content'] ?? '');
            $this->post_status = (string) ($data['post_status'] ?? 'draft');
            $this->post_date = (string) ($data['post_date'] ?? '');
        }
    }
}

if (!function_exists('get_post')) {
    function get_post(int $postId): ?WP_Post
    {
        $data = $GLOBALS['__ana_test_posts'][$postId] ?? null;

        if ($data === null) {
            return null;
        }

        return new WP_Post(array_merge(['ID' => $postId], $data));
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return trim(strip_tags($text));
    }
}

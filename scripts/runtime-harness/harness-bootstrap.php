<?php

declare(strict_types=1);

/**
 * Reusable real-database runtime verification harness — first built for
 * Module 8 Milestones 2-3, promoted here so every future milestone reuses
 * it instead of rebuilding it from scratch in a scratch directory each
 * time (see docs/verification/*-runtime-verification.md for the results
 * this harness has already produced).
 *
 * What "real" means here: real MariaDB (via DB_HOST/DB_NAME/DB_USER/
 * DB_PASSWORD env vars, see scripts/verify-runtime.sh), the real
 * WordPress core wpdb class and dbDelta() function (fetched at verify
 * time by scripts/verify-runtime.sh into --wp-core-dir, never vendored
 * into this repo — see that script for why), and the plugin's own real
 * production entry point (PluginFactory::create()->boot() on
 * plugins_loaded, exactly as ai-news-automator-pro.php calls it).
 *
 * What's shimmed: only the peripheral WordPress API surface (hooks,
 * options, transients, i18n, escaping, minimal post/REST/capability
 * stubs) — mirroring tests/bootstrap.php's approach, extended with a
 * few additions (get_post()/WP_Post, register_rest_route()/
 * WP_REST_Request/WP_REST_Response) that tests/bootstrap.php doesn't
 * need but real-database checklists do.
 *
 * Usage: required by scripts/verify-runtime.sh, which sets the env vars
 * and constants below before requiring this file. Do not require this
 * file directly without that setup — WP_CORE_DIR and DB_* must already
 * be defined.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$wpCoreDir = getenv('WP_CORE_DIR');
if ($wpCoreDir === false || $wpCoreDir === '') {
    fwrite(STDERR, "WP_CORE_DIR env var must be set before requiring harness-bootstrap.php — run via scripts/verify-runtime.sh.\n");
    exit(1);
}

define('ABSPATH', $wpCoreDir . '/');
define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', $wpCoreDir . '/wp-content');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('DB_NAME', getenv('ANA_HARNESS_DB_NAME') ?: 'ana_runtime_harness');
define('DB_USER', getenv('ANA_HARNESS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('ANA_HARNESS_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('ANA_HARNESS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

/* ---------------- Hook system (priority-ordered, dynamic pickup) ---- */

$GLOBALS['__hooks'] = [];

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['__hooks'][$hook][$priority][] = $cb;
    return true;
}

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_action($hook, $cb, $priority, $args);
}

function do_action(string $hook, mixed ...$args): void
{
    $ran = [];
    // Dynamic pickup: callbacks registered for this hook while it is
    // firing (at any priority not yet passed) still run, like real WP.
    $safety = 0;
    do {
        $ranSomething = false;
        $priorities = array_keys($GLOBALS['__hooks'][$hook] ?? []);
        sort($priorities);
        foreach ($priorities as $p) {
            foreach ($GLOBALS['__hooks'][$hook][$p] as $i => $cb) {
                $key = $p . ':' . $i;
                if (isset($ran[$key])) {
                    continue;
                }
                $ran[$key] = true;
                $ranSomething = true;
                $cb(...$args);
            }
        }
    } while ($ranSomething && ++$safety < 20);
    $GLOBALS['__did_actions'][$hook] = ($GLOBALS['__did_actions'][$hook] ?? 0) + 1;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $priorities = array_keys($GLOBALS['__hooks'][$hook] ?? []);
    sort($priorities);
    foreach ($priorities as $p) {
        foreach ($GLOBALS['__hooks'][$hook][$p] as $cb) {
            $value = $cb($value, ...$args);
        }
    }
    return $value;
}

function has_filter(string $hook = '', mixed $cb = false): bool|int
{
    return isset($GLOBALS['__hooks'][$hook]) && $GLOBALS['__hooks'][$hook] !== [];
}

function did_action(string $hook): int
{
    return $GLOBALS['__did_actions'][$hook] ?? 0;
}

function remove_all_filters(string $hook): bool
{
    unset($GLOBALS['__hooks'][$hook]);
    return true;
}

function register_activation_hook(string $file, callable $cb): void
{
    $GLOBALS['__activation_hooks'][$file] = $cb;
}

function register_deactivation_hook(string $file, callable $cb): void
{
    $GLOBALS['__deactivation_hooks'][$file] = $cb;
}

function register_uninstall_hook(string $file, callable $cb): void
{
    $GLOBALS['__uninstall_hooks'][$file] = $cb;
}

/* ---------------- Options / transients (in-memory) ------------------ */

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['__options'][$key] ?? $default;
}

function update_option(string $key, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['__options'][$key] = $value;
    return true;
}

function add_option(string $key, mixed $value = '', string $d = '', mixed $autoload = null): bool
{
    if (isset($GLOBALS['__options'][$key])) {
        return false;
    }
    $GLOBALS['__options'][$key] = $value;
    return true;
}

function delete_option(string $key): bool
{
    unset($GLOBALS['__options'][$key]);
    return true;
}

function get_transient(string $key): mixed
{
    $row = $GLOBALS['__transients'][$key] ?? null;
    if ($row === null) {
        return false;
    }
    if ($row['expires'] !== 0 && $row['expires'] < time()) {
        unset($GLOBALS['__transients'][$key]);
        return false;
    }
    return $row['value'];
}

function set_transient(string $key, mixed $value, int $ttl = 0): bool
{
    $GLOBALS['__transients'][$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];
    return true;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['__transients'][$key]);
    return true;
}

/* ---------------- i18n / escaping / misc ---------------------------- */

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function _e(string $text, string $domain = 'default'): void
{
    echo $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function wp_kses_post(string $content): string
{
    // Simplified stand-in for WordPress core's wp_kses_post() — see
    // docs/adr/0019-ai-generation-pipeline-scope-and-trust-boundary.md.
    $content = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $content);
    $allowed = '<p><a><strong><em><b><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><br><span><img>';
    $content = strip_tags($content, $allowed);
    $content = (string) preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $content);

    return (string) preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '$1=$2#$2', $content);
}

function esc_url_raw(string $url): string
{
    return $url;
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES);
}

function is_singular(): bool
{
    return $GLOBALS['__is_singular'] ?? true;
}

function get_the_ID(): int|false
{
    return $GLOBALS['__current_post_id'] ?? false;
}

function esc_sql(mixed $data): mixed
{
    global $wpdb;
    return $wpdb->_escape($data);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

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

function mbstring_binary_safe_encoding(bool $reset = false): void
{
    // Mirrors WP core's encoding stack (wp-includes/functions.php).
    static $encodings = [];
    if (false === $reset) {
        $encoding = mb_internal_encoding();
        array_push($encodings, $encoding);
        mb_internal_encoding('ISO-8859-1');
    } elseif ($encodings !== []) {
        $encoding = array_pop($encodings);
        mb_internal_encoding($encoding);
    }
}

function reset_mbstring_encoding(): void
{
    mbstring_binary_safe_encoding(true);
}

function seems_utf8(string $str): bool
{
    return (bool) preg_match('//u', $str);
}

function _doing_it_wrong(string $function, string $message, string $version): void
{
}

function wp_load_translations_early(): void
{
}

function wp_debug_backtrace_summary(?string $ignore = null, int $skip = 0, bool $pretty = true): string
{
    return '';
}

function is_multisite(): bool
{
    return false;
}

function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
{
    throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : var_export($message, true)));
}

function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}

function current_time(string $type, bool $gmt = false): string
{
    return gmdate('Y-m-d H:i:s');
}

function wp_generate_uuid4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

function sanitize_text_field(string $str): string
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($str)) ?? '');
}

function sanitize_textarea_field(string $str): string
{
    return trim(strip_tags($str));
}

function sanitize_email(string $email): string
{
    return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}

function is_email(string $email): string|false
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) ?: false;
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function wp_get_environment_type(): string
{
    return 'production';
}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/\\') . '/';
}

function plugin_dir_url(string $file): string
{
    return 'http://localhost/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function get_current_user_id(): int
{
    return $GLOBALS['__current_user_id'] ?? 0;
}

function wp_mail(string $to, string $subject, string $message, mixed $headers = '', mixed $attachments = []): bool
{
    $GLOBALS['__sent_mail'][] = compact('to', 'subject', 'message');
    return true;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    return $GLOBALS['__cron'][$hook] ?? false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    $GLOBALS['__cron'][$hook] = $timestamp;
    return true;
}

function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
{
    unset($GLOBALS['__cron'][$hook]);
    return true;
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    unset($GLOBALS['__cron'][$hook]);
    return 1;
}

function wp_salt(string $scheme = 'auth'): string
{
    return str_repeat('s', 64);
}

/* ---------------- Users / capabilities ------------------------------ */

function user_can(mixed $user, string $capability): bool
{
    $userId = is_object($user) ? ($user->ID ?? 0) : (int) $user;
    $caps = $GLOBALS['__user_caps'][$userId] ?? [];
    return in_array($capability, $caps, true);
}

function current_user_can(string $capability): bool
{
    return user_can(get_current_user_id(), $capability);
}

function get_userdata(int $userId): object|false
{
    if (!isset($GLOBALS['__users'][$userId])) {
        return false;
    }
    return (object) ['ID' => $userId];
}

function get_role(string $role): ?object
{
    $GLOBALS['__roles'][$role] ??= new class {
        public array $capabilities = [];

        public function add_cap(string $cap, bool $grant = true): void
        {
            $this->capabilities[$cap] = $grant;
        }

        public function remove_cap(string $cap): void
        {
            unset($this->capabilities[$cap]);
        }

        public function has_cap(string $cap): bool
        {
            return $this->capabilities[$cap] ?? false;
        }
    };
    return $GLOBALS['__roles'][$role];
}

/* ---------------- Posts (minimal, mirrors tests/bootstrap.php) ------ */

function wp_update_post(array $postarr): int
{
    $id = (int) ($postarr['ID'] ?? 0);
    $GLOBALS['__posts'][$id] = array_merge($GLOBALS['__posts'][$id] ?? [], $postarr);
    return $id;
}

function wp_insert_post(array $postarr, bool $wp_error = false): int
{
    static $next = 1000;
    $id = ++$next;
    $postarr['ID'] = $id;
    $GLOBALS['__posts'][$id] = $postarr;
    return $id;
}

function wp_delete_post(int $postId, bool $forceDelete = false): mixed
{
    unset($GLOBALS['__posts'][$postId], $GLOBALS['__postmeta'][$postId]);
    return true;
}

class WP_Post
{
    public int $ID;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_status = 'draft';
    public string $post_date = '';
    public string $post_modified = '';

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->ID = (int) ($data['ID'] ?? 0);
        $this->post_title = (string) ($data['post_title'] ?? '');
        $this->post_content = (string) ($data['post_content'] ?? '');
        $this->post_status = (string) ($data['post_status'] ?? 'draft');
        $this->post_date = (string) ($data['post_date'] ?? '');
        $this->post_modified = (string) ($data['post_modified'] ?? $this->post_date);
    }
}

function get_post(int $postId): ?WP_Post
{
    return isset($GLOBALS['__posts'][$postId]) ? new WP_Post(array_merge(['ID' => $postId], $GLOBALS['__posts'][$postId])) : null;
}

function update_post_meta(int $postId, string $key, mixed $value): bool
{
    $GLOBALS['__postmeta'][$postId][$key] = $value;
    return true;
}

function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    return $GLOBALS['__postmeta'][$postId][$key] ?? '';
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

/* ---------------- SEO / front-end rendering (Module 9) --------------- */
// Configure via $GLOBALS['__permalinks'][$postId], $GLOBALS['__thumbnails'][$postId],
// $GLOBALS['__bloginfo'][$show], $GLOBALS['__categories'][$postId] (list of
// ['term_id' => int, 'name' => string]) — reuses $GLOBALS['__posts']/__postmeta
// (already populated above) for get_posts().

function get_permalink(int $postId): string|false
{
    if (isset($GLOBALS['__permalinks'][$postId])) {
        return $GLOBALS['__permalinks'][$postId];
    }

    // Matches real WordPress's own behavior: get_permalink() returns
    // SOME URL for any existing post (the ?p=123 fallback when no
    // pretty-permalink structure is configured), never false, unless
    // the post itself doesn't exist.
    return isset($GLOBALS['__posts'][$postId]) ? 'https://harness.test/?p=' . $postId : false;
}

function get_the_post_thumbnail_url(int $postId, string $size = 'post-thumbnail'): string|false
{
    return $GLOBALS['__thumbnails'][$postId] ?? false;
}

function get_bloginfo(string $show = ''): string
{
    return $GLOBALS['__bloginfo'][$show] ?? 'Harness Test Site';
}

function home_url(string $path = ''): string
{
    return 'https://harness.test' . $path;
}

/**
 * @return list<object{term_id: int, name: string}>
 */
function get_the_category(int $postId): array
{
    return $GLOBALS['__categories'][$postId] ?? [];
}

function get_category_link(int $termId): string|false
{
    return 'https://harness.test/category/' . $termId;
}

/**
 * A narrow stand-in — recognizes only the specific args this project's
 * own repositories/services actually pass (post_type, post_status,
 * meta_key, numberposts, exclude, fields), not a general WP_Query.
 *
 * @param array<string, mixed> $args
 * @return list<int|WP_Post>
 */
function get_posts(array $args = []): array
{
    $status = $args['post_status'] ?? 'publish';
    $metaKey = $args['meta_key'] ?? null;
    $exclude = array_map('intval', $args['exclude'] ?? []);
    $fields = $args['fields'] ?? '';
    $limit = $args['numberposts'] ?? -1;

    $results = [];
    foreach ($GLOBALS['__posts'] ?? [] as $postId => $data) {
        $postId = (int) $postId;

        if (in_array($postId, $exclude, true)) {
            continue;
        }

        if ($status !== 'any' && ($data['post_status'] ?? 'draft') !== $status) {
            continue;
        }

        if ($metaKey !== null && !isset($GLOBALS['__postmeta'][$postId][$metaKey])) {
            continue;
        }

        $results[] = $fields === 'ids' ? $postId : new WP_Post(array_merge(['ID' => $postId], $data));

        if ($limit > 0 && count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

/* ---------------- REST API (minimal) --------------------------------- */

class WP_REST_Request
{
    /** @param array<string, mixed> $params */
    public function __construct(private readonly array $params = [])
    {
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}

class WP_REST_Response
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $status = 200
    ) {
    }
}

$GLOBALS['__rest_routes'] = [];

/**
 * @param array<string, mixed>|list<array<string, mixed>> $args
 */
function register_rest_route(string $namespace, string $route, array $args): bool
{
    $isList = array_is_list($args);
    foreach ($isList ? $args : [$args] as $routeArgs) {
        $GLOBALS['__rest_routes'][] = [
            'namespace'           => $namespace,
            'route'               => $route,
            'methods'             => $routeArgs['methods'] ?? 'GET',
            'callback'            => $routeArgs['callback'],
            'permission_callback' => $routeArgs['permission_callback'] ?? null,
        ];
    }

    return true;
}

/* ---------------- Real wpdb against a real MariaDB ------------------- */

require_once ABSPATH . WPINC . '/class-wpdb.php';

$GLOBALS['wpdb'] = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$GLOBALS['wpdb']->set_prefix('wp_');

/* ---------------- Load the plugin through its real entry point ------- */

$pluginFile = getenv('ANA_PLUGIN_FILE');
if ($pluginFile === false || $pluginFile === '') {
    fwrite(STDERR, "ANA_PLUGIN_FILE env var must be set before requiring harness-bootstrap.php.\n");
    exit(1);
}

require_once $pluginFile;

<?php
declare(strict_types=1);

/**
 * Lightweight WordPress function stubs for integration smoke tests.
 *
 * Provides a global registry (WPStubs) that records hook registrations,
 * function calls, and allows tests to inject return values — so we can
 * verify plugin ↔ WordPress wiring without a real WP installation.
 */
final class WPStubs
{
    /** @var array{filter: array<int, array>, action: array<int, array>} */
    public static array $hooks = ['filter' => [], 'action' => []];

    /** @var array<string, list<array>> recorded calls keyed by function name */
    public static array $calls = [];

    /** @var array<string, mixed> preset return values keyed by function name */
    public static array $returns = [];

    public static function reset(): void
    {
        self::$hooks   = ['filter' => [], 'action' => []];
        self::$calls   = [];
        self::$returns = [];
    }

    public static function record(string $fn, array $args): void
    {
        self::$calls[$fn][] = $args;
    }

    /** @return mixed */
    public static function returnFor(string $fn, mixed $default = null)
    {
        return array_key_exists($fn, self::$returns) ? self::$returns[$fn] : $default;
    }

    public static function wasCalled(string $fn): bool
    {
        return !empty(self::$calls[$fn]);
    }

    public static function callCount(string $fn): int
    {
        return count(self::$calls[$fn] ?? []);
    }

    /** Return the args array of the Nth call (0-based). */
    public static function callArgs(string $fn, int $index = 0): ?array
    {
        return self::$calls[$fn][$index] ?? null;
    }
}

/* ------------------------------------------------------------------ */
/*  WordPress constants                                               */
/* ------------------------------------------------------------------ */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/* ------------------------------------------------------------------ */
/*  WordPress hook system stubs                                       */
/* ------------------------------------------------------------------ */

if (!function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        WPStubs::$hooks['filter'][] = [
            'tag' => $tag, 'callback' => $callback,
            'priority' => $priority, 'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $tag, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        WPStubs::$hooks['action'][] = [
            'tag' => $tag, 'callback' => $callback,
            'priority' => $priority, 'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void
    {
        WPStubs::record('register_activation_hook', [$file, $callback]);
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void
    {
        WPStubs::record('register_deactivation_hook', [$file, $callback]);
    }
}

if (!function_exists('register_uninstall_hook')) {
    function register_uninstall_hook(string $file, $callback): void
    {
        WPStubs::record('register_uninstall_hook', [$file, $callback]);
    }
}

/* ------------------------------------------------------------------ */
/*  Template / query stubs                                            */
/* ------------------------------------------------------------------ */

if (!function_exists('is_page')) {
    function is_page($page = ''): bool
    {
        return (bool) WPStubs::returnFor('is_page', false);
    }
}

if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id(): int
    {
        return (int) WPStubs::returnFor('get_queried_object_id', 0);
    }
}

if (!function_exists('get_the_ID')) {
    function get_the_ID()
    {
        return WPStubs::returnFor('get_the_ID', 0);
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post = 0): string
    {
        return (string) WPStubs::returnFor('get_the_title', '');
    }
}

/* ------------------------------------------------------------------ */
/*  Post-meta stubs                                                   */
/* ------------------------------------------------------------------ */

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false)
    {
        WPStubs::record('get_post_meta', [$post_id, $key, $single]);
        $map = WPStubs::returnFor('get_post_meta', []);
        return $map[$key] ?? ($single ? '' : []);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $key, $value, $prev_value = '')
    {
        WPStubs::record('update_post_meta', [$post_id, $key, $value, $prev_value]);
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $key, $value = '')
    {
        WPStubs::record('delete_post_meta', [$post_id, $key, $value]);
        return true;
    }
}

/* ------------------------------------------------------------------ */
/*  User stubs (certificate tests)                                     */
/* ------------------------------------------------------------------ */

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return (bool) WPStubs::returnFor('is_user_logged_in', false);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) WPStubs::returnFor('get_current_user_id', 0);
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta(int $user_id, string $key = '', bool $single = false)
    {
        WPStubs::record('get_user_meta', [$user_id, $key, $single]);
        $storage = WPStubs::returnFor('user_meta_storage', []);
        if (!is_array($storage)) {
            return $single ? '' : [];
        }
        $userMap = $storage[$user_id] ?? [];
        $val     = $userMap[$key] ?? ($single ? '' : []);
        return $single ? $val : [$val];
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta(int $user_id, string $meta_key, $meta_value, $prev_value = '')
    {
        WPStubs::record('update_user_meta', [$user_id, $meta_key, $meta_value, $prev_value]);
        if (!isset(WPStubs::$returns['user_meta_storage'])) {
            WPStubs::$returns['user_meta_storage'] = [];
        }
        if (!isset(WPStubs::$returns['user_meta_storage'][$user_id])) {
            WPStubs::$returns['user_meta_storage'][$user_id] = [];
        }
        WPStubs::$returns['user_meta_storage'][$user_id][$meta_key] = $meta_value;
        return true;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $user_id)
    {
        WPStubs::record('get_userdata', [$user_id]);
        $obj = WPStubs::returnFor('get_userdata', null);
        return $obj;
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, $timestamp = false, bool $gmt = false): string
    {
        WPStubs::record('date_i18n', [$format, $timestamp, $gmt]);
        $override = WPStubs::returnFor('date_i18n', null);
        if ($override !== null) {
            return (string) $override;
        }
        return $timestamp ? date($format, (int) $timestamp) : date($format);
    }
}

/* ------------------------------------------------------------------ */
/*  Nonce / capability stubs                                          */
/* ------------------------------------------------------------------ */

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
    {
        return '';
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action = ''): int|false
    {
        return WPStubs::returnFor('wp_verify_nonce', false);
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        return 'stub_nonce';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool
    {
        return (bool) WPStubs::returnFor('current_user_can', false);
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action = '', $query_arg = false, bool $die = true)
    {
        WPStubs::record('check_ajax_referer', [$action, $query_arg, $die]);
        return 1;
    }
}

/* ------------------------------------------------------------------ */
/*  Sanitization stubs                                                */
/* ------------------------------------------------------------------ */

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim($str);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return $url;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint): int
    {
        return abs((int) $maybeint);
    }
}

/* ------------------------------------------------------------------ */
/*  JSON stubs                                                        */
/* ------------------------------------------------------------------ */

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_send_json_success')) {
    /**
     * Stub records the call and throws WPDieException to simulate die().
     * Tests should catch WPDieException when calling handlers that use this.
     */
    function wp_send_json_success($data = null, ?int $status_code = null): void
    {
        WPStubs::record('wp_send_json_success', [$data, $status_code]);
        throw new WPDieException('wp_send_json_success');
    }
}

if (!function_exists('wp_send_json_error')) {
    /**
     * Stub records the call and throws WPDieException to simulate die().
     * Tests should catch WPDieException when calling handlers that use this.
     */
    function wp_send_json_error($data = null, ?int $status_code = null): void
    {
        WPStubs::record('wp_send_json_error', [$data, $status_code]);
        throw new WPDieException('wp_send_json_error');
    }
}

/**
 * Exception thrown by wp_send_json_* stubs to simulate die() behavior.
 * Test code should catch this exception to verify handler responses.
 */
class WPDieException extends \RuntimeException {}

/* ------------------------------------------------------------------ */
/*  Enqueue stubs                                                     */
/* ------------------------------------------------------------------ */

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void
    {
        WPStubs::record('wp_enqueue_style', [$handle, $src, $deps, $ver, $media]);
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, $in_footer = false): void
    {
        WPStubs::record('wp_enqueue_script', [$handle, $src, $deps, $ver, $in_footer]);
    }
}

if (!function_exists('wp_enqueue_media')) {
    function wp_enqueue_media(array $args = []): void
    {
        WPStubs::record('wp_enqueue_media', [$args]);
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool
    {
        WPStubs::record('wp_localize_script', [$handle, $object_name, $l10n]);
        return true;
    }
}

if (!function_exists('add_thickbox')) {
    function add_thickbox(): void
    {
        WPStubs::record('add_thickbox', []);
    }
}

/* ------------------------------------------------------------------ */
/*  Meta-box stub                                                     */
/* ------------------------------------------------------------------ */

if (!function_exists('add_meta_box')) {
    function add_meta_box(string $id, string $title, callable $callback, $screen = null, string $context = 'advanced', string $priority = 'default', ?array $callback_args = null): void
    {
        WPStubs::record('add_meta_box', [$id, $title, $callback, $screen, $context, $priority]);
    }
}

/* ------------------------------------------------------------------ */
/*  Plugin path / URL stubs                                           */
/* ------------------------------------------------------------------ */

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string
    {
        return dirname($file) . '/';
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string
    {
        return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

/* ------------------------------------------------------------------ */
/*  Screen stub                                                       */
/* ------------------------------------------------------------------ */

if (!function_exists('get_current_screen')) {
    function get_current_screen(): ?object
    {
        return WPStubs::returnFor('get_current_screen', null);
    }
}

/* ------------------------------------------------------------------ */
/*  Attachment stubs                                                  */
/* ------------------------------------------------------------------ */

if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachment_id): string|false
    {
        return WPStubs::returnFor('wp_get_attachment_url', false);
    }
}

if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type(int $post_id = 0): string|false
    {
        return WPStubs::returnFor('get_post_mime_type', false);
    }
}

/* ------------------------------------------------------------------ */
/*  Theme stubs                                                       */
/* ------------------------------------------------------------------ */

if (!function_exists('get_header')) {
    function get_header(string $name = null, array $args = []): void {}
}

if (!function_exists('get_footer')) {
    function get_footer(string $name = null, array $args = []): void {}
}

/* ------------------------------------------------------------------ */
/*  Transient stubs (used by analytics rate limiting)                  */
/* ------------------------------------------------------------------ */

if (!function_exists('get_transient')) {
    function get_transient(string $transient)
    {
        WPStubs::record('get_transient', [$transient]);
        $map = WPStubs::returnFor('transients', []);
        return $map[$transient] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, $value, int $expiration = 0): bool
    {
        WPStubs::record('set_transient', [$transient, $value, $expiration]);
        return true;
    }
}

/* ------------------------------------------------------------------ */
/*  Page template stub (used by analytics tutorial validation)         */
/* ------------------------------------------------------------------ */

if (!function_exists('get_page_template_slug')) {
    function get_page_template_slug($post = null): string
    {
        WPStubs::record('get_page_template_slug', [$post]);
        $map = WPStubs::returnFor('page_template_slugs', []);
        $post_id = is_object($post) ? $post->ID : (int) $post;
        return $map[$post_id] ?? '';
    }
}

/* ------------------------------------------------------------------ */
/*  Option stubs                                                      */
/* ------------------------------------------------------------------ */

if (!function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool
    {
        WPStubs::record('update_option', [$option, $value, $autoload]);
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false)
    {
        WPStubs::record('get_option', [$option, $default]);
        return WPStubs::returnFor('get_option_' . $option, $default);
    }
}

/* ------------------------------------------------------------------ */
/*  Date / time stubs                                                 */
/* ------------------------------------------------------------------ */

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        WPStubs::record('current_time', [$type, $gmt]);
        $override = WPStubs::returnFor('current_time', null);
        if ($override !== null) {
            return $override;
        }
        return date($type);
    }
}

if (!function_exists('get_the_date')) {
    function get_the_date(string $format = '', $post = null): string
    {
        WPStubs::record('get_the_date', [$format, $post]);
        return (string) WPStubs::returnFor('get_the_date', '');
    }
}

/* ------------------------------------------------------------------ */
/*  Post stubs                                                        */
/* ------------------------------------------------------------------ */

if (!function_exists('get_post')) {
    function get_post($post = null, string $output = 'OBJECT', string $filter = 'raw')
    {
        WPStubs::record('get_post', [$post, $output, $filter]);
        return WPStubs::returnFor('get_post', null);
    }
}

/* ------------------------------------------------------------------ */
/*  Misc stubs (analytics, dashboard, i18n)                           */
/* ------------------------------------------------------------------ */

if (!function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
        WPStubs::record('nocache_headers', []);
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []): void
    {
        WPStubs::record('wp_die', [$message, $title, $args]);
        throw new WPDieException('wp_die: ' . $message);
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title, string $fallback_title = '', string $context = 'save'): string
    {
        return strtolower(preg_replace('/[^a-z0-9\-]/', '-', strtolower($title)));
    }
}

// Note: dbDelta is NOT stubbed here because PBSG_Analytics::create_tables()
// does `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` which defines
// the real dbDelta. Stubbing it here would cause a redeclaration fatal error.
// Schema tests verify SQL generation via source-code inspection instead.

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url, ?array $protocols = null, string $_context = 'display'): string
    {
        return $url;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        WPStubs::record('add_shortcode', [$tag, $callback]);
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback = null, string $icon_url = '', ?int $position = null): string
    {
        WPStubs::record('add_menu_page', [$page_title, $menu_title, $capability, $menu_slug, $callback, $icon_url, $position]);
        return $menu_slug;
    }
}

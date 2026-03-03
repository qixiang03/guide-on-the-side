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
    function wp_send_json_success($data = null, ?int $status_code = null): void
    {
        WPStubs::record('wp_send_json_success', [$data, $status_code]);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, ?int $status_code = null): void
    {
        WPStubs::record('wp_send_json_error', [$data, $status_code]);
    }
}

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

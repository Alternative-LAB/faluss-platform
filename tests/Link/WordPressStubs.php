<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['link_test_actions'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['link_test_filters'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('add_shortcode')) {
        function add_shortcode(string $tag, callable $callback): void
        {
            $GLOBALS['link_test_shortcodes'][$tag] = $callback;
        }
    }

    if (!function_exists('did_action')) {
        function did_action(string $hook): int
        {
            unset($hook);

            return 0;
        }
    }

    if (!function_exists('is_admin')) {
        function is_admin(): bool
        {
            return (bool) ($GLOBALS['link_test_is_admin'] ?? false);
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return $capability === 'manage_options' && (bool) ($GLOBALS['link_test_manage_options'] ?? false);
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return $GLOBALS['link_test_options'][$name] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $name, mixed $value, bool $autoload = true): bool
        {
            $GLOBALS['link_test_option_updates'][] = compact('name', 'value', 'autoload');
            $GLOBALS['link_test_options'][$name] = $value;

            return true;
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $value): string
        {
            return trim((string) $value);
        }
    }

    if (!function_exists('wp_unslash')) {
        function wp_unslash(mixed $value): mixed
        {
            return $value;
        }
    }

    if (!function_exists('wp_verify_nonce')) {
        function wp_verify_nonce(string $nonce, string $action): int|false
        {
            unset($nonce, $action);

            return !empty($GLOBALS['link_test_nonce_valid']) ? 1 : false;
        }
    }

    if (!class_exists('LinkTestWpDie', false)) {
        final class LinkTestWpDie extends \RuntimeException {}
    }

    if (!function_exists('wp_die')) {
        function wp_die(string $message = ''): never
        {
            throw new LinkTestWpDie($message);
        }
    }

    if (!class_exists('LinkTestRedirect', false)) {
        final class LinkTestRedirect extends \RuntimeException {}
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://faluss.me/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(string $url): never
        {
            throw new LinkTestRedirect($url);
        }
    }

    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool
        {
            return (bool) ($GLOBALS['link_test_logged_in'] ?? false);
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['link_test_user_id'] ?? 0);
        }
    }

    if (!function_exists('plugins_url')) {
        function plugins_url(string $path = '', string $plugin = ''): string
        {
            unset($plugin);

            return 'https://faluss.me/wp-content/plugins/faluss-platform/' . ltrim($path, '/');
        }
    }

    if (!function_exists('wp_register_style')) {
        function wp_register_style(string $handle, string $source, array $dependencies = [], mixed $version = false): void
        {
            $GLOBALS['link_test_styles'][$handle] = compact('source', 'dependencies', 'version');
        }
    }

    if (!function_exists('wp_register_script')) {
        function wp_register_script(string $handle, string $source, array $dependencies = [], mixed $version = false, bool $footer = false): void
        {
            $GLOBALS['link_test_scripts'][$handle] = compact('source', 'dependencies', 'version', 'footer');
        }
    }
}

namespace Faluss\Platform\Link {
    function link_test_reset(): void
    {
        $GLOBALS['link_test_actions'] = [];
        $GLOBALS['link_test_filters'] = [];
        $GLOBALS['link_test_shortcodes'] = [];
        $GLOBALS['link_test_logged_in'] = false;
        $GLOBALS['link_test_user_id'] = 0;
        $GLOBALS['link_test_is_admin'] = false;
        $GLOBALS['link_test_manage_options'] = false;
        $GLOBALS['link_test_options'] = [];
        $GLOBALS['link_test_option_updates'] = [];
        $GLOBALS['link_test_nonce_valid'] = false;
        $GLOBALS['link_test_styles'] = [];
        $GLOBALS['link_test_scripts'] = [];
    }
}

<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
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
            return false;
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
        $GLOBALS['link_test_styles'] = [];
        $GLOBALS['link_test_scripts'] = [];
    }
}

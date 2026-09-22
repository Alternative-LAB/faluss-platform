<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['identity_test_actions'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['identity_test_filters'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
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
            return !empty($GLOBALS['identity_test_logged_in']);
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['identity_test_user_id'] ?? 0);
        }
    }

    if (!function_exists('load_plugin_textdomain')) {
        function load_plugin_textdomain(string $domain, bool $deprecated = false, string $path = ''): bool
        {
            unset($deprecated);
            $GLOBALS['identity_test_textdomains'][$domain] = $path;

            return true;
        }
    }

    if (!function_exists('plugin_basename')) {
        function plugin_basename(string $file): string
        {
            return 'faluss-platform/' . basename($file);
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
            $GLOBALS['identity_test_styles'][$handle] = compact('source', 'dependencies', 'version');
        }
    }

    if (!function_exists('wp_register_script')) {
        function wp_register_script(string $handle, string $source, array $dependencies = [], mixed $version = false, bool $footer = false): void
        {
            $GLOBALS['identity_test_scripts'][$handle] = compact('source', 'dependencies', 'version', 'footer');
        }
    }
}

namespace Faluss\Platform\Identity {
    function identity_test_reset(): void
    {
        $GLOBALS['identity_test_actions'] = [];
        $GLOBALS['identity_test_filters'] = [];
        $GLOBALS['identity_test_styles'] = [];
        $GLOBALS['identity_test_scripts'] = [];
        $GLOBALS['identity_test_textdomains'] = [];
        $GLOBALS['identity_test_logged_in'] = false;
        $GLOBALS['identity_test_user_id'] = 0;
        unset($GLOBALS['wpdb']);
    }
}

<?php

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/IdentityClient/WordPressStubs.php';

    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['portal_test_actions'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['portal_test_filters'][$hook] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('add_shortcode')) {
        function add_shortcode(string $tag, callable $callback): void
        {
            $GLOBALS['portal_test_shortcodes'][$tag] = $callback;
        }
    }

    if (!function_exists('did_action')) {
        function did_action(string $hook): int
        {
            return $hook === 'plugins_loaded' ? (int) ($GLOBALS['portal_test_did_plugins_loaded'] ?? 0) : 0;
        }
    }

    if (!function_exists('doing_action')) {
        function doing_action(?string $hook = null): bool
        {
            return $hook === 'plugins_loaded' && !empty($GLOBALS['portal_test_doing_plugins_loaded']);
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $value): bool
        {
            return $value instanceof WP_Error;
        }
    }

    if (!function_exists('plugins_url')) {
        function plugins_url(string $path = '', string $plugin = ''): string
        {
            unset($plugin);

            return 'https://faluss.com/wp-content/plugins/faluss-platform/' . ltrim($path, '/');
        }
    }
}

namespace Faluss\Platform\Portal {
    function portal_test_reset(): void
    {
        \Faluss\Platform\IdentityClient\identity_client_test_reset();
        $GLOBALS['portal_test_actions'] = [];
        $GLOBALS['portal_test_filters'] = [];
        $GLOBALS['portal_test_shortcodes'] = [];
        $GLOBALS['portal_test_did_plugins_loaded'] = 1;
        $GLOBALS['portal_test_doing_plugins_loaded'] = false;
        $GLOBALS['portal_test_logged_in'] = false;
        $GLOBALS['portal_test_current_user'] = null;
        $GLOBALS['portal_test_link'] = null;
    }
}

<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['token_engine_test_actions'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('plugins_url')) {
        function plugins_url(string $path = '', string $plugin = ''): string
        {
            unset($plugin);

            return 'https://faluss.com/wp-content/plugins/faluss-platform/' . ltrim($path, '/');
        }
    }

    if (!function_exists('wp_enqueue_style')) {
        function wp_enqueue_style(string $handle, string $source, array $dependencies = [], mixed $version = false): void
        {
            $GLOBALS['token_engine_test_styles'][$handle] = compact('source', 'dependencies', 'version');
        }
    }

    if (!function_exists('wp_enqueue_script')) {
        function wp_enqueue_script(string $handle, string $source, array $dependencies = [], mixed $version = false, bool $footer = false): void
        {
            $GLOBALS['token_engine_test_scripts'][$handle] = compact('source', 'dependencies', 'version', 'footer');
        }
    }
}

namespace Faluss\Platform\TokenEngine {
    function token_engine_test_reset(): void
    {
        $GLOBALS['token_engine_test_actions'] = [];
        $GLOBALS['token_engine_test_styles'] = [];
        $GLOBALS['token_engine_test_scripts'] = [];
        unset($GLOBALS['wpdb']);
    }
}

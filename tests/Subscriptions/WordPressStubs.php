<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['subscriptions_test_actions'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
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
            $GLOBALS['subscriptions_test_styles'][$handle] = compact('source', 'dependencies', 'version');
        }
    }

    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $value): bool
        {
            return $value instanceof WP_Error;
        }
    }

    if (!function_exists('__')) {
        function __(string $message): string
        {
            return $message;
        }
    }

    if (!class_exists('WP_Error')) {
        final class WP_Error
        {
            public function __construct(private readonly string $code = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }
        }
    }
}

namespace Faluss\Platform\Subscriptions {
    function subscriptions_test_reset(): void
    {
        $GLOBALS['subscriptions_test_actions'] = [];
        $GLOBALS['subscriptions_test_styles'] = [];
        unset($GLOBALS['wpdb']);
    }
}

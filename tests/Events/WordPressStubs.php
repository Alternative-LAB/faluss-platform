<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-events-tests/');
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10): void
        {
            $GLOBALS['events_test_actions'][$hook][$priority][] = $callback;
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback): void
        {
            $GLOBALS['events_test_filters'][$hook][] = $callback;
        }
    }

    if (!function_exists('did_action')) {
        function did_action(string $hook): int
        {
            return $hook === 'plugins_loaded' ? 1 : 0;
        }
    }
}

namespace Faluss\Platform\Events {
    function events_test_reset(): void
    {
        $GLOBALS['events_test_actions'] = [];
        $GLOBALS['events_test_filters'] = [];
    }
}

<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1): void
        {
            $GLOBALS['federation_test_actions'][$hook][$priority][] = [$callback, $acceptedArgs];
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1): void
        {
            $GLOBALS['federation_test_filters'][$hook][$priority][] = [$callback, $acceptedArgs];
        }
    }

    if (!function_exists('do_action')) {
        function do_action($hook, ...$arguments): void
        {
            $GLOBALS['federation_test_fired'][$hook] = ($GLOBALS['federation_test_fired'][$hook] ?? 0) + 1;
        }
    }

    if (!function_exists('is_admin')) {
        function is_admin(): bool
        {
            return true;
        }
    }

    if (!function_exists('federation_test_reset')) {
        function federation_test_reset(): void
        {
            $GLOBALS['federation_test_actions'] = [];
            $GLOBALS['federation_test_filters'] = [];
            $GLOBALS['federation_test_fired'] = [];
        }
    }
}

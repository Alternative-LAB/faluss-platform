<?php

declare(strict_types=1);

namespace Faluss\Platform\Core {
    // The updater is outside role admission; keep the bootstrap test hermetic.
    final class UpdateClient
    {
        public static function boot(string $pluginFile): void
        {
            unset($pluginFile);
        }
    }
}

namespace {
    define('ABSPATH', '/tmp/faluss-fans-role-test/');

    $GLOBALS['fans_test_activation_hooks'] = [];
    $GLOBALS['fans_test_deactivation_hooks'] = [];
    $GLOBALS['fans_test_actions'] = [];

    function register_activation_hook(string $pluginFile, callable $callback): void
    {
        $GLOBALS['fans_test_activation_hooks'][] = $callback;
    }

    function register_deactivation_hook(string $pluginFile, callable $callback): void
    {
        $GLOBALS['fans_test_deactivation_hooks'][] = $callback;
    }

    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        $GLOBALS['fans_test_actions'][$hook][] = $callback;
    }
}

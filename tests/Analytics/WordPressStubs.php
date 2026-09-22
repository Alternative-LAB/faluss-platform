<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-analytics-tests/');
    }

}

namespace Faluss\Platform\Analytics {
    function analytics_test_reset(): void
    {
        $GLOBALS['analytics_test_actions'] = [];
        unset($GLOBALS['wpdb']);
    }
}

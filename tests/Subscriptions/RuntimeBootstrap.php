<?php

$subscriptionsTestRoot = dirname(__DIR__, 2);
$subscriptionsLegacy = $subscriptionsTestRoot . '/src/Subscriptions/Legacy/includes/';

if (!defined('FALUSS_SUBSCRIPTIONS_FILE')) {
    define('FALUSS_SUBSCRIPTIONS_FILE', $subscriptionsTestRoot . '/faluss-platform.php');
}
if (!defined('FALUSS_SUBSCRIPTIONS_DIR')) {
    define('FALUSS_SUBSCRIPTIONS_DIR', $subscriptionsTestRoot . '/');
}
if (!defined('FALUSS_SUBSCRIPTIONS_URL')) {
    define('FALUSS_SUBSCRIPTIONS_URL', 'https://example.test/wp-content/plugins/faluss-platform/');
}
if (!defined('FALUSS_SUBSCRIPTIONS_VERSION')) {
    define('FALUSS_SUBSCRIPTIONS_VERSION', '0.2.7');
}

foreach ([
    'class-faluss-subscriptions-schema.php',
    'class-faluss-subscriptions-catalog.php',
    'class-faluss-subscriptions-audit.php',
    'class-faluss-subscriptions-repository.php',
    'class-faluss-subscriptions-trials.php',
    'class-faluss-subscriptions-entitlements.php',
    'class-faluss-subscriptions-resolver.php',
    'class-faluss-subscriptions-stripe-config.php',
    'class-faluss-subscriptions-stripe-sdk.php',
    'class-faluss-subscriptions-billing.php',
    'class-faluss-subscriptions-webhooks.php',
    'class-faluss-subscriptions-notifications.php',
    'class-faluss-subscriptions-returns.php',
    'class-faluss-subscriptions-diagnostics.php',
    'class-faluss-subscriptions-admin-notices.php',
    'class-faluss-subscriptions-admin.php',
] as $subscriptionsTestFile) {
    require_once $subscriptionsLegacy . $subscriptionsTestFile;
}

Faluss_Subscriptions_Admin::boot();
Faluss_Subscriptions_Webhooks::boot();
Faluss_Subscriptions_Notifications::boot();
Faluss_Subscriptions_Returns::boot();

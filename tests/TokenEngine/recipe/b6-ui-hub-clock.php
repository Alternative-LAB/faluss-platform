<?php
declare(strict_types=1);

// Optional application-test clock for the native completion case only. Never packaged or installed on ordinary sites.
require_once WP_PLUGIN_DIR . '/faluss-platform/vendor/autoload.php';
add_filter('rest_pre_dispatch', static function ($response, WP_REST_Server $server, WP_REST_Request $request) {
    if ($response !== null || $request->get_route() !== '/faluss-b3-recipe/v1/barrier') { return $response; }
    $path = dirname(rtrim(ABSPATH, '/')) . '/barrier-primary-clock';
    if (!is_file($path)) { return $response; }
    global $wpdb;
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb, 'hub');
    if (is_link($path) || (fileperms($path) & 0777) !== 0600
        || preg_match('/^[0-9]{1,11}\.[0-9]{6}$/D', file_get_contents($path)) !== 1
        || $wpdb->query('SET timestamp=' . file_get_contents($path)) === false) {
        return new WP_Error('pf_fixture_clock_required', 'Private fixture clock unavailable.', ['status' => 503]);
    }
    return $response;
}, 1, 3);

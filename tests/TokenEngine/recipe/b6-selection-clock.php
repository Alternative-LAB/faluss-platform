<?php

declare(strict_types=1);

// Application scenario clock for the existing ranked route, physically impossible on ordinary sites.
require_once WP_PLUGIN_DIR . '/faluss-platform/vendor/autoload.php';
add_filter('rest_pre_dispatch',static function ($response,WP_REST_Server $server,WP_REST_Request $request) {
    if ($response!==null || $request->get_route()!=='/faluss-b3-recipe/v1/ranked') { return $response; }
    global $wpdb;
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'hub');
    $path=dirname(rtrim(ABSPATH,'/')).'/corpus-sql-clock';
    if (!is_file($path) || is_link($path) || (fileperms($path)&0777)!==0600) { throw new RuntimeException('Private scenario clock unavailable'); }
    $clock=file_get_contents($path);
    if (preg_match('/^[0-9]{1,11}\.[0-9]{6}$/D',$clock)!==1 || $wpdb->query('SET timestamp='.$clock)===false) {
        throw new RuntimeException('Private scenario clock unavailable');
    }
    return $response;
},1,3);

<?php

declare(strict_types=1);

// Application HTTP test adapter only. Not installed, packaged or admitted on ordinary sites.
require_once WP_PLUGIN_DIR . '/faluss-platform/vendor/autoload.php';
global $wpdb;
\Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$root = dirname(rtrim(ABSPATH,'/'));
$settings = json_decode(file_get_contents($root . '/b6-ui-settings.json'),true,16,JSON_THROW_ON_ERROR);
foreach (['SSO','UI','CREATOR_PROFILES','EDITORIAL'] as $feature) {
    if (!defined('FALUSS_PLATFORM_FANS_' . $feature)) { define('FALUSS_PLATFORM_FANS_' . $feature,true); }
}
define('FALUSS_FANS_SSO_CLIENT_ID','fixture.fans.identity');
define('FALUSS_FANS_SSO_CLIENT_SECRET',$settings['fictitious_client_secret']);
// Production requires HTTPS while the approved physical test gate requires exact loopback HTTP.
// This test transport shim changes URL schemes only, never authentication or permissions.
$http = (string) constant('WP_HOME'); $https = preg_replace('~^http:~','https:',$http);
add_filter('home_url',static fn (string $url): string => str_replace($http,(string) $https,$url));
if (PHP_SAPI === 'cli-server') { ob_start(static fn (string $html): string => str_replace((string) $https,$http,$html)); }
$clock = trim(file_get_contents($root . '/corpus-sql-clock'));
if (!is_numeric($clock)) { throw new RuntimeException('Private fixture clock unavailable'); }
$wpdb->query($wpdb->prepare('SET timestamp=%f',(float) $clock));
add_filter('faluss_fans_hof_closed_delivery',static function () use ($wpdb,$settings,$root): \Faluss\Platform\Fans\Hof\ClosedRankingDelivery {
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
    $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
    $peer = new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
    $inbox = new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($wpdb,$peer,$settings['origin'],'1.0.0');
    return new \Faluss\Platform\Fans\Hof\ClosedRankingDelivery($wpdb,
        new \Faluss\Platform\Fans\Hof\ClosedOriginOpening($wpdb,$inbox,$settings['origin']),
        new \Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead($wpdb,$inbox,$peer),
        new \Faluss\Platform\Fans\Hof\ClosedRankingProjectionStore($wpdb,$peer,$settings['origin'],'1.0.0'));
});

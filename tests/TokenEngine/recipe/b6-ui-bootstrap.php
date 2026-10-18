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
// Optional application scenario, still behind the same physical fixture gate above.
if (($settings['automatic_messages'] ?? false) === true) {
    foreach (['FALUSS_PLATFORM_FANS_MESSAGING','FALUSS_FANS_MESSAGING_POLICY_ATTESTED'] as $feature) {
        if (!defined($feature)) { define($feature,true); }
    }
    add_filter('faluss_fans_closed_pf_thanks_delivery',static function () use ($wpdb,$settings,$root): \Faluss\Platform\Fans\PfContract\ClosedPurchasedThanksDelivery {
        \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
        $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer = new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        $inbox = new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($wpdb,$peer,$settings['origin'],'1.0.0');
        return new \Faluss\Platform\Fans\PfContract\ClosedPurchasedThanksDelivery($wpdb,
            new \Faluss\Platform\Fans\Hof\ClosedOriginOpening($wpdb,$inbox,$settings['origin']),
            new \Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead($wpdb,$inbox,$peer),
            new \Faluss\Platform\Fans\PfContract\ClosedCorpusInbox($wpdb,$peer,$settings['origin'],'1.0.0'),$peer);
    });
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
// Application transport wiring for the same admitted fictitious peer; no environment setup or admission.
add_filter('faluss_fans_hof_closed_sessions',static function () use ($wpdb,$settings,$root): \Faluss\Platform\Fans\Hof\ClosedSessionLifecycle {
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
    $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
    $peer = new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
    $inbox = new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($wpdb,$peer,$settings['origin'],'1.0.0');
    $endpoint = str_replace('/corpus','/barrier',trim(file_get_contents($root . '/corpus-endpoint')));
    return new \Faluss\Platform\Fans\Hof\ClosedSessionLifecycle($wpdb,$inbox,
        new \Faluss\Platform\Fans\PfContract\ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$endpoint),$settings['origin']);
});
// The separate current-primary read uses the same already admitted fictitious peer.
add_filter('faluss_fans_hof_closed_participation',static function () use ($wpdb,$settings,$root): \Faluss\Platform\Fans\Hof\ClosedParticipationLifecycle {
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
    $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
    $peer = new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
    $inbox = new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($wpdb,$peer,$settings['origin'],'1.0.0');
    $endpoint = str_replace('/corpus','/barrier',trim(file_get_contents($root . '/corpus-endpoint')));
    return new \Faluss\Platform\Fans\Hof\ClosedParticipationLifecycle($wpdb,$inbox,
        new \Faluss\Platform\Fans\PfContract\ClosedBarrierClient($wpdb,$inbox,'recipe-fans-k1',$endpoint),$settings['origin']);
});
add_filter('faluss_fans_hof_closed_session_freshness',static function () use ($wpdb,$settings,$root): \Faluss\Platform\Fans\Hof\ClosedSessionFreshness {
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
    $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
    $peer = new \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
    $inbox = new \Faluss\Platform\Fans\PfContract\ClosedBarrierInbox($wpdb,$peer,$settings['origin'],'1.0.0');
    $reader = new \Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryRead($wpdb,$inbox,$peer);
    $endpoint = str_replace('/corpus','/barrier',trim(file_get_contents($root . '/corpus-endpoint')));
    return new \Faluss\Platform\Fans\Hof\ClosedSessionFreshness($wpdb,$inbox,$reader,
        new \Faluss\Platform\Fans\PfContract\ClosedBarrierPrimaryClient($wpdb,$inbox,$reader,'recipe-fans-k1',$endpoint));
});
// Fixture fault selection only; it does not change a signature, permission or Hub result.
add_filter('http_response',static function ($response, array $arguments, string $url) use ($wpdb,$root) {
    $fault = $root . '/b6-ui-drop-response';
    if (!is_file($fault) || is_link($fault) || (fileperms($fault) & 0777) !== 0600
        || !str_ends_with($url,'rest_route=/faluss-b3-recipe/v1/barrier') || wp_remote_retrieve_response_code($response) !== 200) { return $response; }
    \Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment::assertIsolated($wpdb,'fans');
    $remaining = trim(file_get_contents($fault));
    if (!in_array($remaining, ['1', '2', '3'], true)) { throw new RuntimeException('Invalid isolated delivery fault'); }
    if ($remaining !== '1') { file_put_contents($fault,(string) ((int) $remaining - 1)); return $response; }
    unlink($fault);
    return new WP_Error('http_request_failed','Fictitious successful response intentionally lost.');
},10,3);

<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierGateway;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;

// Fixture MU loader only. Copied constants/files on an ordinary WordPress cannot expose this route.
add_action('rest_api_init',static function (): void {
    global $wpdb;
    try { ClosedEnvironment::assertIsolated($wpdb,'hub'); }
    catch (ModelViolation) { return; }
    require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
    register_rest_route('faluss-b3-recipe/v1','/barrier',[
        'methods' => 'POST','permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            global $wpdb;
            $root = dirname(rtrim(ABSPATH,'/'));
            try {
                ClosedEnvironment::assertIsolated($wpdb,'hub');
                if (strtolower($request->get_header('content-type')) !== 'application/json'
                    || strlen($request->get_body()) > BarrierTransport::MAX_WIRE) { throw new ModelViolation('pf_request_rejected'); }
                $path = $root . '/hub-barrier-trust.json';
                if (!is_file($path) || is_link($path) || (fileperms($path)&0777)!==0600) { throw new ModelViolation('pf_fixture_policy_required'); }
                $policy = json_decode(file_get_contents($path),true,20,JSON_THROW_ON_ERROR);
                // Private primary clock fixture only; never an HTTP field, site setting or production hook.
                $clock = $root . '/barrier-primary-clock';
                if (is_file($clock)) {
                    if (is_link($clock) || (fileperms($clock)&0777)!==0600
                        || preg_match('/^[0-9]{1,11}\.[0-9]{6}$/D',file_get_contents($clock)) !== 1) {
                        throw new ModelViolation('pf_fixture_clock_required');
                    }
                    if ($wpdb->query('SET timestamp=' . file_get_contents($clock)) === false) { throw new ModelViolation('pf_fixture_clock_required'); }
                }
                $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
                $wire = (new ClosedBarrierGateway($wpdb,$peer,'recipe-hub-k1'))->handle($request->get_body());
                $fault = $root . '/barrier-http-fault';
                if (is_file($fault) && file_get_contents($fault)==='drop-after-commit') {
                    unlink($fault); header('Cache-Control: private, no-store'); header('Content-Type: application/json');
                    header('Content-Length: ' . (strlen($wire)+1)); flush(); exit;
                }
                $response = new WP_REST_Response(['barrier_wire' => $wire],200);
            } catch (ModelViolation $error) {
                file_put_contents($root . '/barrier-http-diagnostic',$error->reason);
                $response = new WP_REST_Response(['error' => 'pf_request_rejected'],403);
            } catch (Throwable $error) {
                file_put_contents($root . '/barrier-http-diagnostic',get_class($error));
                $response = new WP_REST_Response(['error' => 'pf_fixture_unavailable'],503);
            }
            $response->header('Cache-Control','private, no-store, max-age=0'); $response->header('CDN-Cache-Control','no-store');
            return $response;
        },
    ]);
    add_filter('rest_pre_serve_request',static function (bool $served, WP_HTTP_Response $response, WP_REST_Request $request): bool {
        $data = $response->get_data();
        if ($request->get_route()==='/faluss-b3-recipe/v1/barrier' && $response->get_status()===200
            && is_array($data) && is_string($data['barrier_wire'] ?? null)) { echo $data['barrier_wire']; return true; }
        return $served;
    },10,3);
});

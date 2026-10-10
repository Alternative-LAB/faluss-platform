<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusGateway;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CorpusTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

// MU fixture only. The plugin bootstrap never registers these routes.
function b3_corpus_recipe_peer(): PeerPolicy
{
    $path = dirname(rtrim(ABSPATH,'/')) . '/' . FALUSS_PLATFORM_ROLE . '-corpus-trust.json';
    if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) { throw new ModelViolation('pf_fixture_policy_required'); }
    $policy = json_decode(file_get_contents($path),true,20,JSON_THROW_ON_ERROR);
    return new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
}

add_action('rest_api_init',static function (): void {
    global $wpdb;
    try { ClosedEnvironment::assertIsolated($wpdb,'hub'); }
    catch (ModelViolation) { return; }
    require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
    register_rest_route('faluss-b3-recipe/v1','/corpus',[
        'methods' => 'POST','permission_callback' => '__return_true',
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            global $wpdb;
            try {
                ClosedEnvironment::assertIsolated($wpdb,'hub');
                if (strtolower($request->get_header('content-type')) !== 'application/json'
                    || strlen($request->get_body()) > CorpusTransport::MAX_REQUEST_WIRE) { throw new ModelViolation('pf_request_rejected'); }
                // Accelerate this fixture's primary SQL clock only. Never accept a clock from HTTP.
                $path = dirname(rtrim(ABSPATH,'/')) . '/corpus-sql-clock';
                if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) { throw new ModelViolation('pf_fixture_clock_required'); }
                $clock = file_get_contents($path);
                if (preg_match('/^[1-9][0-9]{9}\.[0-9]{6}$/D',$clock) !== 1 || $wpdb->query('SET timestamp=' . $clock) === false) {
                    throw new ModelViolation('pf_fixture_clock_required');
                }
                $wire = (new ClosedCorpusGateway($wpdb,b3_corpus_recipe_peer(),'recipe-hub-k1'))->handle($request->get_body());
                $fault = dirname(rtrim(ABSPATH,'/')) . '/corpus-http-fault';
                if (is_file($fault) && file_get_contents($fault) === 'drop-after-materialize') {
                    unlink($fault);
                    // The actual owner materialization has committed; lose only its HTTP response.
                    header('Cache-Control: private, no-store'); header('Content-Type: application/json');
                    header('Content-Length: ' . (strlen($wire)+1)); flush(); exit;
                }
                if (is_file($fault) && file_get_contents($fault) === 'redirect') {
                    unlink($fault); $response = new WP_REST_Response(null,302);
                    $response->header('Location',WP_HOME . '/index.php?rest_route=/faluss-b3-recipe/v1/follow-probe');
                } else { $response = new WP_REST_Response(['corpus_wire' => $wire],200); }
            } catch (ModelViolation $error) {
                file_put_contents(dirname(rtrim(ABSPATH,'/')) . '/corpus-http-diagnostic',$error->reason);
                $response = new WP_REST_Response(['error' => 'pf_request_rejected'],403);
            } catch (Throwable $error) {
                file_put_contents(dirname(rtrim(ABSPATH,'/')) . '/corpus-http-diagnostic',get_class($error));
                $response = new WP_REST_Response(['error' => 'pf_fixture_unavailable'],503);
            }
            $response->header('Cache-Control','private, no-store, max-age=0'); $response->header('CDN-Cache-Control','no-store');
            return $response;
        },
    ]);
    register_rest_route('faluss-b3-recipe/v1','/follow-probe',[
        'methods' => 'GET','permission_callback' => '__return_true',
        'callback' => static function (): WP_REST_Response {
            file_put_contents(dirname(rtrim(ABSPATH,'/')) . '/corpus-redirect-followed','followed');
            return new WP_REST_Response(null,200);
        },
    ]);
    add_filter('rest_pre_serve_request',static function (bool $served, WP_HTTP_Response $response, WP_REST_Request $request): bool {
        $data = $response->get_data();
        if ($request->get_route() === '/faluss-b3-recipe/v1/corpus' && $response->get_status() === 200
            && is_array($data) && is_string($data['corpus_wire'] ?? null)) { echo $data['corpus_wire']; return true; }
        return $served;
    },10,3);
});

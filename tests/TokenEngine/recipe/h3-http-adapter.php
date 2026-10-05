<?php

declare(strict_types=1);

// Test MU loader, copied only into generated fixtures. Never part of the plugin bootstrap.
use Faluss\Platform\Fans\PfContract\ClosedClient;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedGateway;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;

function h3_fixture_peer(): PeerPolicy
{
    $root = dirname(rtrim(ABSPATH, '/'));
    $path = $root . '/' . FALUSS_PLATFORM_ROLE . '-trust.json';
    if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
        throw new ModelViolation('pf_fixture_policy_required');
    }
    $policy = json_decode(file_get_contents($path), true, 20, JSON_THROW_ON_ERROR);
    return new PeerPolicy($policy['node'], $policy['audience'], $policy['permissions'], $policy['keys']);
}

add_action('rest_api_init', static function (): void {
    global $wpdb;
    try { ClosedEnvironment::assertIsolated($wpdb, FALUSS_PLATFORM_ROLE); }
    catch (ModelViolation) { return; } // A copied MU file/config on an ordinary site creates no route.
    require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
    register_rest_route('faluss-h3-recipe/v1', '/pf', [
        'methods' => 'POST',
        'permission_callback' => static function (): bool {
            return FALUSS_PLATFORM_ROLE === 'hub' || (is_user_logged_in() && !current_user_can('manage_options'));
        },
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            global $wpdb;
            try {
                ClosedEnvironment::assertIsolated($wpdb, FALUSS_PLATFORM_ROLE);
                if (strtolower($request->get_header('content-type')) !== 'application/json' || strlen($request->get_body()) > 65536) {
                    throw new ModelViolation('pf_request_rejected');
                }
                $peer = h3_fixture_peer();
                if (FALUSS_PLATFORM_ROLE === 'hub') {
                    $keyId = file_get_contents(dirname(rtrim(ABSPATH, '/')) . '/hub-key-id');
                    $wire = (new ClosedGateway($wpdb, $peer, $keyId))->handle($request->get_body());
                    $fault = dirname(rtrim(ABSPATH, '/')) . '/http-fault';
                    if (is_file($fault) && file_get_contents($fault) === 'drop-after-consume') {
                        unlink($fault);
                        // The real owner transaction has committed. Deliberately lose the HTTP body.
                        header('Cache-Control: private, no-store');
                        header('Content-Type: application/json');
                        header('Content-Length: ' . (strlen($wire) + 1));
                        flush();
                        exit;
                    }
                    $response = new WP_REST_Response(['h3_wire' => $wire], 200);
                } else {
                    $input = $request->get_json_params();
                    \Faluss\Platform\TokenEngine\PurchasedPf\ModelValues::exactKeys($input, ['input','operation','lookup_operation']);
                    $endpoint = file_get_contents(dirname(rtrim(ABSPATH, '/')) . '/hub-endpoint');
                    $result = (new ClosedClient($wpdb, $peer, 'recipe-fans-k1', $endpoint))->exchange($input['input'], $input['operation'], $input['lookup_operation']);
                    $response = new WP_REST_Response($result, 200);
                }
            } catch (ModelViolation $error) {
                $response = new WP_REST_Response(['error' => FALUSS_PLATFORM_ROLE === 'hub' ? 'pf_request_rejected' : $error->reason], 403);
            } catch (Throwable) {
                $response = new WP_REST_Response(['error' => 'pf_fixture_unavailable'], 503);
            }
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('CDN-Cache-Control', 'no-store');
            return $response;
        },
    ]);
    add_filter('rest_pre_serve_request', static function (bool $served, WP_HTTP_Response $response, WP_REST_Request $request): bool {
        $data = $response->get_data();
        if (FALUSS_PLATFORM_ROLE === 'hub' && $request->get_route() === '/faluss-h3-recipe/v1/pf'
            && $response->get_status() === 200 && is_array($data) && is_string($data['h3_wire'] ?? null)) {
            echo $data['h3_wire']; // Preserve exact canonical envelope bytes; no WP JSON re-encoding.
            return true;
        }
        return $served;
    }, 10, 3);
});

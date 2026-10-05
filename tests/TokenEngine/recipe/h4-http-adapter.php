<?php

declare(strict_types=1);

// Explicit test MU loader. Never copied or registered by the plugin itself.
use Faluss\Platform\Fans\PfContract\ClosedSnapshotClient;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedSnapshotGateway;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotEnvironment;

add_action('rest_api_init', static function (): void {
    global $wpdb;
    try { SnapshotEnvironment::assertIsolated($wpdb, FALUSS_PLATFORM_ROLE); }
    catch (ModelViolation) { return; }
    require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
    register_rest_route('faluss-h4-recipe/v1', '/snapshot', [
        'methods' => 'POST',
        'permission_callback' => static fn (): bool => FALUSS_PLATFORM_ROLE === 'hub' || (is_user_logged_in() && !current_user_can('manage_options')),
        'callback' => static function (WP_REST_Request $request): WP_REST_Response {
            global $wpdb;
            try {
                SnapshotEnvironment::assertIsolated($wpdb, FALUSS_PLATFORM_ROLE);
                if (strtolower($request->get_header('content-type')) !== 'application/json' || strlen($request->get_body()) > 65536) {
                    throw new ModelViolation('pf_request_rejected');
                }
                $root = dirname(rtrim(ABSPATH, '/'));
                $peer = h3_fixture_peer();
                if (FALUSS_PLATFORM_ROLE === 'hub') {
                    $wire = (new ClosedSnapshotGateway($wpdb, $peer, file_get_contents($root . '/hub-key-id')))->handle($request->get_body());
                    $fault = $root . '/h4-http-fault';
                    if (is_file($fault) && file_get_contents($fault) === 'drop-after-snapshot') {
                        unlink($fault);
                        header('Cache-Control: private, no-store');
                        header('Content-Length: ' . (strlen($wire) + 1));
                        flush();
                        exit;
                    }
                    $response = new WP_REST_Response(['h4_wire' => $wire], 200);
                } else {
                    $input = $request->get_json_params();
                    ModelValues::exactKeys($input, ['read_id']);
                    $epochFile = $root . '/snapshot-epoch';
                    if (!is_file($epochFile) || is_link($epochFile) || (fileperms($epochFile) & 0777) !== 0600) {
                        throw new ModelViolation('pf_fixture_policy_required');
                    }
                    $response = new WP_REST_Response((new ClosedSnapshotClient($wpdb, $peer, 'recipe-fans-k1',
                        file_get_contents($root . '/snapshot-endpoint'), file_get_contents($epochFile)))->advance($input['read_id']), 200);
                }
            } catch (ModelViolation $error) {
                $response = new WP_REST_Response(['error' => FALUSS_PLATFORM_ROLE === 'hub' ? 'pf_request_rejected' : $error->reason], 403);
            } catch (Throwable) { $response = new WP_REST_Response(['error' => 'pf_fixture_unavailable'], 503); }
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            $response->header('CDN-Cache-Control', 'no-store');
            return $response;
        },
    ]);
    add_filter('rest_pre_serve_request', static function (bool $served, WP_HTTP_Response $response, WP_REST_Request $request): bool {
        $data = $response->get_data();
        if (FALUSS_PLATFORM_ROLE === 'hub' && $request->get_route() === '/faluss-h4-recipe/v1/snapshot'
            && $response->get_status() === 200 && is_array($data) && is_string($data['h4_wire'] ?? null)) {
            echo $data['h4_wire'];
            return true;
        }
        return $served;
    }, 10, 3);
});

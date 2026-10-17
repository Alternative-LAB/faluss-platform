<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierAdmission;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedBarrierTransportSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\BarrierTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb, 'hub');
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Disposable CLI only'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$root = dirname(rtrim(ABSPATH, '/'));
$path = realpath($args[0]);
if ($path === false || dirname($path) !== $root || (fileperms($path) & 0777) !== 0600) {
    throw new RuntimeException('Private fixture input required');
}
$input = json_decode(file_get_contents($path), true, 40, JSON_THROW_ON_ERROR);
if (!in_array($input['slot'] ?? '', ['1', '2'], true)) { throw new RuntimeException('Invalid worker slot'); }
$policy = json_decode(file_get_contents($root . '/hub-barrier-trust.json'), true, 20, JSON_THROW_ON_ERROR);
$peer = new PeerPolicy($policy['node'], $policy['audience'], $policy['permissions'], $policy['keys']);
$payload = SignedEnvelope::open(SignedEnvelope::BARRIER_REQUEST, CanonicalJson::object($input['wire'], BarrierTransport::MAX_WIRE), $peer, time());
$request = BarrierTransport::request($payload, $peer, time(), $payload['contract']);
$table = ClosedBarrierTransportSchema::tables($wpdb)['nonces'];
add_filter('query', static function (string $sql) use ($root, $input, $table): string {
    if (str_starts_with($sql, 'INSERT INTO `' . $table . '`')) {
        $marker = $root . '/nonce-interval-ready-' . $input['slot'];
        file_put_contents($marker, 'ready'); chmod($marker, 0600);
        $until = microtime(true) + 10;
        while (!is_file($root . '/nonce-interval-go') && microtime(true) < $until) { usleep(10000); }
        if (!is_file($root . '/nonce-interval-go')) { throw new RuntimeException('Nonce coordination expired'); }
    }
    return $sql;
}, PHP_INT_MAX);
try {
    (new ClosedBarrierAdmission($wpdb))->accept($peer, $request, hash('sha256', $input['wire']), $payload['contract']);
    $result = ['admitted' => true];
} catch (ModelViolation $error) {
    $result = ['error' => $error->reason];
}
echo wp_json_encode($result, JSON_THROW_ON_ERROR);

<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedClient;
use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb, FALUSS_PLATFORM_ROLE);
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]), true, 30, JSON_THROW_ON_ERROR);
try {
    if ($input['action'] === 'sign') {
        $request = ClosedClient::request(AttributionIntent::fromArray($input['intent']), $input['operation'],
            $input['key'], $input['lookup'] ?? '', 'recipe-fans-k1');
        $outer = CanonicalJson::object($request['wire'], 65536);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url']), 49152);
        $context = CanonicalJson::object(SignedEnvelope::decode($payload['context']['payload_base64url']));
        $context = array_replace($context, $input['context'] ?? []);
        $payload['context'] = SignedEnvelope::seal(SignedEnvelope::CONTEXT, CanonicalJson::encode($context), $input['context_key'] ?? 'recipe-fans-k1');
        if (($input['bad_context_signature'] ?? false) === true) {
            $signature = SignedEnvelope::decode($payload['context']['signature_base64url']);
            $signature[0] = chr(ord($signature[0]) ^ 1);
            $payload['context']['signature_base64url'] = Faluss_Federation_Crypto::base64url_encode($signature);
        }
        $payload = array_replace($payload, $input['request'] ?? []);
        $wire = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::REQUEST, CanonicalJson::encode($payload), $input['request_key'] ?? 'recipe-fans-k1'));
        $result = ['wire' => $wire, 'nonce' => $request['nonce']];
    } elseif ($input['action'] === 'accept') {
        $policy = json_decode(file_get_contents(dirname(rtrim(ABSPATH, '/')) . '/fans-trust.json'), true, 20, JSON_THROW_ON_ERROR);
        $peer = new PeerPolicy($policy['node'], $policy['audience'], $policy['permissions'], $policy['keys']);
        $client = new ClosedClient($wpdb, $peer, 'recipe-fans-k1', file_get_contents(dirname(rtrim(ABSPATH, '/')) . '/hub-endpoint'));
        $result = $client->accept($input['wire'], AttributionIntent::fromArray($input['intent']),
            $input['operation'], $input['nonce'], $input['digest']);
    } elseif ($input['action'] === 'alter-response') {
        $outer = CanonicalJson::object($input['wire'], 65536);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url']), 49152);
        if (isset($input['receipt'])) {
            $receipt = CanonicalJson::object(SignedEnvelope::decode($payload['result']['receipt']['payload_base64url']));
            $receipt = array_replace($receipt, $input['receipt']);
            $payload['result']['receipt'] = SignedEnvelope::seal(SignedEnvelope::RECEIPT, CanonicalJson::encode($receipt), $input['receipt_key'] ?? 'recipe-hub-k1');
        }
        if (($input['bad_receipt_signature'] ?? false) === true) {
            $signature = SignedEnvelope::decode($payload['result']['receipt']['signature_base64url']);
            $signature[0] = chr(ord($signature[0]) ^ 1);
            $payload['result']['receipt']['signature_base64url'] = Faluss_Federation_Crypto::base64url_encode($signature);
        }
        $payload = array_replace($payload, $input['response'] ?? []);
        $result = ['wire' => CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::RESPONSE, CanonicalJson::encode($payload), $input['response_key'] ?? 'recipe-hub-k1'))];
    } else {
        throw new RuntimeException('Unknown fixture operation.');
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result);

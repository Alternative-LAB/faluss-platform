<?php

declare(strict_types=1);

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankedTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,FALUSS_PLATFORM_ROLE);
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);
try {
    if ($input['action']==='public') {
        $pair = sodium_crypto_sign_seed_keypair(SignedEnvelope::decode(FALUSS_FEDERATION_PRIVATE_SEED,32));
        $result = ['public_key' => SignedEnvelope::encode(sodium_crypto_sign_publickey($pair))]; sodium_memzero($pair);
    } elseif ($input['action']==='sign') {
        $sealed = RankedTransport::sealRequest(RankedIntent::fromArray($input['intent']),$input['operation'],$input['key'],
            $input['lookup'] ?? '','recipe-fans-k1',time()+60);
        $outer = CanonicalJson::object($sealed['wire'],RankedTransport::MAX_WIRE);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'],49152),49152);
        $context = CanonicalJson::object(SignedEnvelope::decode($payload['context']['payload_base64url']));
        $context = array_replace($context,$input['context'] ?? []);
        $payload['context'] = SignedEnvelope::seal($input['context_domain'] ?? SignedEnvelope::RANKING_CONTEXT,
            CanonicalJson::encode($context),$input['context_key'] ?? 'recipe-fans-k1');
        $payload = array_replace($payload,$input['request'] ?? []);
        $result = ['wire' => CanonicalJson::encode(SignedEnvelope::seal($input['domain'] ?? SignedEnvelope::RANKING_REQUEST,
            CanonicalJson::encode($payload),$input['request_key'] ?? 'recipe-fans-k1')),'nonce' => $sealed['nonce']];
    } elseif ($input['action']==='alter-response') {
        $outer = CanonicalJson::object($input['wire'],RankedTransport::MAX_WIRE);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'],49152),49152);
        $result = ['wire' => CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::RANKING_RESPONSE,
            CanonicalJson::encode(array_replace($payload,$input['response'] ?? [])),$input['response_key'] ?? 'recipe-hub-k1'))];
    } else {
        $root = dirname(rtrim(ABSPATH,'/'));
        $policy = json_decode(file_get_contents($root . '/fans-ranked-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        $payload = SignedEnvelope::open(SignedEnvelope::RANKING_RESPONSE,CanonicalJson::object($input['wire'],RankedTransport::MAX_WIRE),$peer,time());
        $result = RankedTransport::response($payload,$peer,RankedIntent::fromArray($input['intent']),$input['operation'],
            $input['nonce'],$input['digest'],time());
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);

<?php

declare(strict_types=1);

use Faluss\Platform\Fans\PfContract\ClosedCorpusClient;
use Faluss\Platform\TokenEngine\PurchasedPf\ClosedCorpusTransportSchema;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CorpusTransport;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\PeerPolicy;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SignedEnvelope;

global $wpdb;
ClosedEnvironment::assertIsolated($wpdb,FALUSS_PLATFORM_ROLE);
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Fixture CLI only.'); }
require_once WP_PLUGIN_DIR . '/faluss-platform/src/Federation/Legacy/includes/class-faluss-federation-crypto.php';
$input = json_decode(file_get_contents($args[0]),true,40,JSON_THROW_ON_ERROR);
try {
    if ($input['action'] === 'public') {
        $pair = sodium_crypto_sign_seed_keypair(SignedEnvelope::decode(FALUSS_FEDERATION_PRIVATE_SEED,32));
        $result = ['public_key' => SignedEnvelope::encode(sodium_crypto_sign_publickey($pair))]; sodium_memzero($pair);
    }
    elseif ($input['action'] === 'ready') { $result = ['ready' => ClosedCorpusTransportSchema::ready($wpdb)]; }
    elseif ($input['action'] === 'install') {
        ClosedCorpusTransportSchema::installForRecipe($wpdb); $result = ['ready' => ClosedCorpusTransportSchema::ready($wpdb)];
    } elseif ($input['action'] === 'sign') {
        $request = CorpusTransport::sealRequest($input['fields'],$input['key'],'recipe-fans-k1',time()+60);
        $outer = CanonicalJson::object($request['wire'],CorpusTransport::MAX_REQUEST_WIRE);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'],49152),49152);
        $context = CanonicalJson::object(SignedEnvelope::decode($payload['context']['payload_base64url']));
        $context = array_replace($context,$input['context'] ?? []);
        $payload['context'] = SignedEnvelope::seal(SignedEnvelope::CORPUS_CONTEXT,CanonicalJson::encode($context),$input['context_key'] ?? 'recipe-fans-k1');
        if (($input['bad_context_signature'] ?? false) === true) {
            $signature = SignedEnvelope::decode($payload['context']['signature_base64url']); $signature[0] = chr(ord($signature[0]) ^ 1);
            $payload['context']['signature_base64url'] = SignedEnvelope::encode($signature);
        }
        $payload = array_replace($payload,$input['request'] ?? []);
        $result = ['wire' => CanonicalJson::encode(SignedEnvelope::seal($input['domain'] ?? SignedEnvelope::CORPUS_REQUEST,
            CanonicalJson::encode($payload),$input['request_key'] ?? 'recipe-fans-k1')),'nonce' => $request['nonce']];
    } elseif ($input['action'] === 'alter-response') {
        $outer = CanonicalJson::object($input['wire'],CorpusTransport::MAX_RESPONSE_WIRE);
        $payload = CanonicalJson::object(SignedEnvelope::decode($outer['payload_base64url'],4194304),4194304);
        $payload = array_replace($payload,$input['response'] ?? []);
        $result = ['wire' => CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::CORPUS_RESPONSE,CanonicalJson::encode($payload),$input['response_key'] ?? 'recipe-hub-k1'))];
    } else {
        $root = dirname(rtrim(ABSPATH,'/')); $policy = json_decode(file_get_contents($root . '/fans-corpus-trust.json'),true,20,JSON_THROW_ON_ERROR);
        $peer = new PeerPolicy($policy['node'],$policy['audience'],$policy['permissions'],$policy['keys']);
        $client = new ClosedCorpusClient($wpdb,$peer,'recipe-fans-k1',$input['endpoint'] ?? file_get_contents($root . '/corpus-endpoint'),
            $input['origin'] ?? $input['fields']['origin_id'],'1.0.0');
        if ($input['action'] === 'exchange') { $result = $client->exchange($input['fields'],$input['key']); }
        elseif ($input['action'] === 'accept') { $result = $client->accept($input['wire'],$input['fields'],$input['nonce'],$input['digest']); }
        else { throw new RuntimeException('Unknown closed corpus operation.'); }
    }
} catch (ModelViolation $error) { $result = ['error' => $error->reason]; }
echo wp_json_encode($result,JSON_THROW_ON_ERROR);

<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit 0.3 wire only. No nonce admission, economic dispatch, route or authority registration. */
final class RankedTransport
{
    public const MAX_WIRE = 66048;

    /** @return array{wire:string,nonce:string} */
    public static function sealRequest(RankedIntent $intent, string $operation, string $key, string $lookup,
        string $keyId, int $expires): array
    {
        TransportMessage::operation($operation,$lookup); ModelValues::keyHash($key);
        if ($intent->base->values['client_authority'] !== 'fixture.fans') { throw new ModelViolation('pf_fixture_peer_required'); }
        $now = time(); $nonce = bin2hex(random_bytes(32));
        $base = ['contract' => RankedIntent::CONTRACT,'issuer' => 'fixture.fans','audience' => 'fixture.hub',
            'operation' => $operation,'nonce' => $nonce,'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$now),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z',min($now+60,$expires))];
        DelegatedContext::fresh($base['issued_at'],$base['expires_at'],$now);
        $context = ['kind' => SignedEnvelope::RANKING_CONTEXT,'key_sha256' => hash('sha256',$key),
            'lookup_operation' => $lookup,'intent' => $intent->values] + $base;
        $payload = ['kind' => SignedEnvelope::RANKING_REQUEST,'operation_key' => $key,'lookup_operation' => $lookup,
            'context' => SignedEnvelope::seal(SignedEnvelope::RANKING_CONTEXT,CanonicalJson::encode($context),$keyId)] + $base;
        $wire = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::RANKING_REQUEST,CanonicalJson::encode($payload),$keyId));
        if (strlen($wire) > self::MAX_WIRE) { throw new ModelViolation('pf_payload_size'); }
        return ['wire' => $wire,'nonce' => $nonce];
    }

    /** Outer signature is verified under RANKING_REQUEST before this validator.
     * @param array<string,mixed> $payload
     * @return array{operation:string,nonce:string,key:string,lookup:string,intent:RankedIntent} */
    public static function request(array $payload, PeerPolicy $peer, int $now): array
    {
        ModelValues::exactKeys($payload,['contract','kind','issuer','audience','operation','nonce','issued_at','expires_at','operation_key','lookup_operation','context']);
        if ($payload['contract'] !== RankedIntent::CONTRACT || $payload['kind'] !== SignedEnvelope::RANKING_REQUEST
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || !is_string($payload['operation']) || !is_string($payload['lookup_operation'])
            || !is_string($payload['operation_key']) || !is_array($payload['context'])) { throw new ModelViolation('pf_ranking_request_mismatch'); }
        $operation = $payload['operation']; $lookup = $payload['lookup_operation'];
        TransportMessage::operation($operation,$lookup); ModelValues::keyHash($payload['operation_key']);
        $nonce = DelegatedContext::nonce($payload['nonce']); DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],$now);
        $context = SignedEnvelope::open(SignedEnvelope::RANKING_CONTEXT,$payload['context'],$peer,$now);
        if (($context['issued_at'] ?? null) !== $payload['issued_at'] || ($context['expires_at'] ?? null) !== $payload['expires_at']) {
            throw new ModelViolation('pf_ranking_context_mismatch');
        }
        $intent = RankedDelegation::validate($context,$peer,$operation,$nonce,$payload['operation_key'],$lookup,$now);
        return ['operation' => $operation,'nonce' => $nonce,'key' => $payload['operation_key'],'lookup' => $lookup,'intent' => $intent];
    }

    /** A confirmed reply authenticates its original receipt, never a current corrected score.
     * @param array<string,mixed> $payload
     * @return array{outcome:string,result:array<string,mixed>} */
    public static function response(array $payload, PeerPolicy $peer, RankedIntent $intent, string $operation,
        string $nonce, string $digest, int $now): array
    {
        if (!in_array($operation,['reserve','confirm','release','lookup'],true)) { throw new ModelViolation('pf_invalid_operation'); }
        DelegatedContext::nonce($nonce); RankingValues::digest($digest); $peer->allow('pf.' . $operation);
        if (($payload['contract'] ?? null) !== RankedIntent::CONTRACT || ($payload['kind'] ?? null) !== SignedEnvelope::RANKING_RESPONSE) {
            throw new ModelViolation('pf_ranking_response_mismatch');
        }
        // Shared state/freshness checks only. The old wire validator itself still rejects 0.3.
        $common = $payload; $common['contract'] = DelegatedContext::CONTRACT; $common['kind'] = SignedEnvelope::RESPONSE;
        $answer = TransportMessage::response($common,$peer,$operation,$nonce,$digest,$now);
        if ($answer['outcome'] === 'ok' && $answer['result']['state'] === 'confirmed') {
            $receipt = SignedEnvelope::open(SignedEnvelope::RANKING_RECEIPT,$answer['result']['receipt'],$peer,$now);
            RankedReceipt::validate($receipt,$peer->node,$peer->audience,$intent);
        }
        return $answer;
    }
}

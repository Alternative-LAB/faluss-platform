<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Non-economic admission messages only. Nonce admission and owner-origin validation remain SQL responsibilities. */
final class BarrierTransport
{
    public const CONTRACT = 'hub.purchased-pf.ranking-barriers/1.0.0';
    public const MAX_WIRE = 66048;
    private const FIELDS = ['operation','action_id','origin_id','policy_version','lookup_operation','object'];

    /** @param array<string,mixed> $fields */
    public static function fields(array $fields): void
    {
        ModelValues::exactKeys($fields,self::FIELDS);
        ModelValues::uuid($fields['action_id']); ModelValues::uuid($fields['origin_id']); ModelValues::version($fields['policy_version']);
        if (!in_array($fields['operation'],['register','close','lookup'],true) || !is_array($fields['object'])
            || ($fields['operation'] === 'lookup' ? !in_array($fields['lookup_operation'],['register','close'],true) : $fields['lookup_operation'] !== '')) {
            throw new ModelViolation('pf_barrier_request_mismatch');
        }
        $operation = self::operation($fields);
        if ($operation === 'register') {
            $descriptor = RankingBarrier::descriptor($fields['object']);
            if ($descriptor['content']['origin_id'] !== $fields['origin_id'] || $descriptor['content']['policy_version'] !== $fields['policy_version']) {
                throw new ModelViolation('pf_barrier_context_mismatch');
            }
        } else { RankingBarrier::closeReference($fields['object']); }
    }

    /** @param array<string,mixed> $fields
     * @return array{wire:string,nonce:string} */
    public static function sealRequest(array $fields, string $key, string $keyId, int $expires): array
    {
        self::fields($fields); ModelValues::keyHash($key); $now = time(); $nonce = bin2hex(random_bytes(32));
        $base = ['contract' => self::CONTRACT,'issuer' => 'fixture.fans','audience' => 'fixture.hub','nonce' => $nonce,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$now),'expires_at' => gmdate('Y-m-d\TH:i:s\Z',min($now+60,$expires))] + $fields;
        DelegatedContext::fresh($base['issued_at'],$base['expires_at'],$now);
        $context = ['kind' => SignedEnvelope::BARRIER_CONTEXT,'key_sha256' => hash('sha256',$key),
            'object_sha256' => hash('sha256',CanonicalJson::encode($fields['object']))] + array_diff_key($base,['object' => true]);
        $payload = ['kind' => SignedEnvelope::BARRIER_REQUEST,'operation_key' => $key,
            'context' => SignedEnvelope::seal(SignedEnvelope::BARRIER_CONTEXT,CanonicalJson::encode($context),$keyId)] + $base;
        $wire = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::BARRIER_REQUEST,CanonicalJson::encode($payload),$keyId));
        if (strlen($wire) > self::MAX_WIRE) { throw new ModelViolation('pf_payload_size'); }
        return ['wire' => $wire,'nonce' => $nonce];
    }

    /** Outer signature must already be authenticated in the BARRIER_REQUEST domain.
     * @param array<string,mixed> $payload
     * @return array<string,mixed> */
    public static function request(array $payload, PeerPolicy $peer, int $now): array
    {
        ModelValues::exactKeys($payload,array_merge(self::FIELDS,['contract','kind','issuer','audience','operation_key','nonce','issued_at','expires_at','context']));
        $fields = array_intersect_key($payload,array_flip(self::FIELDS)); self::fields($fields);
        if ($payload['contract'] !== self::CONTRACT || $payload['kind'] !== SignedEnvelope::BARRIER_REQUEST
            || $peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub'
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || !is_string($payload['operation_key']) || !is_array($payload['context'])) { throw new ModelViolation('pf_barrier_request_mismatch'); }
        self::permission($peer,$fields); DelegatedContext::nonce($payload['nonce']); ModelValues::keyHash($payload['operation_key']);
        DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],$now);
        $context = SignedEnvelope::open(SignedEnvelope::BARRIER_CONTEXT,$payload['context'],$peer,$now);
        $expected = ['kind' => SignedEnvelope::BARRIER_CONTEXT,'key_sha256' => hash('sha256',$payload['operation_key']),
            'object_sha256' => hash('sha256',CanonicalJson::encode($fields['object']))]
            + array_diff_key($payload,array_flip(['kind','operation_key','context','object']));
        if (CanonicalJson::encode($context) !== CanonicalJson::encode($expected)) { throw new ModelViolation('pf_barrier_context_mismatch'); }
        return $fields + array_intersect_key($payload,array_flip(['operation_key','nonce','issued_at','expires_at']));
    }

    /** No session content is echoed in a response. Its digest binds the complete request action.
     * @param array<string,mixed> $fields
     * @return array<string,mixed> */
    public static function responseFields(array $fields): array
    {
        self::fields($fields);
        return ['object_sha256' => hash('sha256',CanonicalJson::encode($fields['object']))] + array_diff_key($fields,['object' => true]);
    }

    /** @param array<string,mixed> $payload
     * @param array<string,mixed> $fields
     * @return array{outcome:string,result:array<string,mixed>} */
    public static function response(array $payload, PeerPolicy $peer, array $fields, string $nonce, string $digest, int $now): array
    {
        $expected = self::responseFields($fields); DelegatedContext::nonce($nonce); RankingValues::digest($digest);
        ModelValues::exactKeys($payload,array_merge(array_keys($expected),['contract','kind','issuer','audience','nonce','request_sha256','issued_at','expires_at','outcome','result']));
        if ($payload['contract'] !== self::CONTRACT || $payload['kind'] !== SignedEnvelope::BARRIER_RESPONSE
            || $peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || CanonicalJson::encode(array_intersect_key($payload,$expected)) !== CanonicalJson::encode($expected)
            || $payload['nonce'] !== $nonce || $payload['request_sha256'] !== $digest
            || !in_array($payload['outcome'],['ok','unknown','refused'],true) || !is_array($payload['result'])) { throw new ModelViolation('pf_barrier_response_mismatch'); }
        self::permission($peer,$fields); DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],$now);
        $result = $payload['result'];
        if ($payload['outcome'] !== 'ok') {
            ModelValues::exactKeys($result,['reason']);
            if (!is_string($result['reason']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$result['reason']) !== 1) { throw new ModelViolation('pf_barrier_response_mismatch'); }
        } elseif ($fields['operation'] !== 'lookup' || $result !== ['state' => 'not_found']) {
            $operation = self::operation($fields);
            ModelValues::exactKeys($result,array_merge(['operation','barrier_key','version','content_sha256','effective_at','state'],$operation === 'close' ? ['reason'] : []));
            $ref = $operation === 'register' ? RankingBarrier::reference($fields['object']['content']) : RankingBarrier::closeReference($fields['object']);
            foreach (array_intersect_key($ref,array_flip(['barrier_key','version','content_sha256','reason'])) as $field => $value) {
                if ($result[$field] !== $value) { throw new ModelViolation('pf_barrier_response_mismatch'); }
            }
            RankingValues::utc($result['effective_at']);
            if ($result['operation'] !== $operation || !in_array($result['state'],$operation === 'register' ? ['active','closed','superseded'] : ['closed','superseded'],true)) {
                throw new ModelViolation('pf_barrier_response_mismatch');
            }
        }
        return ['outcome' => $payload['outcome'],'result' => $result];
    }

    /** @param array<string,mixed> $fields */
    private static function operation(array $fields): string
    { return $fields['operation'] === 'lookup' ? $fields['lookup_operation'] : $fields['operation']; }

    /** @param array<string,mixed> $fields */
    private static function permission(PeerPolicy $peer, array $fields): void
    { $peer->allow('pf.ranking.context.' . self::operation($fields)); if ($fields['operation'] === 'lookup') { $peer->allow('pf.lookup'); } }
}

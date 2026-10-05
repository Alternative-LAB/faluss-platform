<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Public wire DTO. No HTTP registration, peer admission or economic dispatch. */
final class TransportMessage
{
    /** @param array<string,mixed> $payload
     *  @return array{operation:string,nonce:string,key:string,lookup:string,intent:AttributionIntent}
     */
    public static function request(array $payload, PeerPolicy $peer, int $now): array
    {
        ModelValues::exactKeys($payload, ['contract','kind','issuer','audience','operation','nonce',
            'issued_at','expires_at','operation_key','lookup_operation','context']);
        if ($payload['contract'] !== DelegatedContext::CONTRACT || $payload['kind'] !== SignedEnvelope::REQUEST
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || !is_string($payload['operation']) || !in_array($payload['operation'], ['reserve','confirm','release','lookup'], true)
            || !is_string($payload['operation_key']) || !is_string($payload['lookup_operation'])
            || !is_array($payload['context'])
        ) {
            throw new ModelViolation('pf_request_mismatch');
        }
        $operation = $payload['operation'];
        $lookup = $payload['lookup_operation'];
        self::operation($operation, $lookup);
        $nonce = DelegatedContext::nonce($payload['nonce']);
        ModelValues::keyHash($payload['operation_key']);
        DelegatedContext::fresh($payload['issued_at'], $payload['expires_at'], $now);
        $context = SignedEnvelope::open(SignedEnvelope::CONTEXT, $payload['context'], $peer, $now);
        $intent = DelegatedContext::validate($context, $peer, $operation, $nonce, $payload['operation_key'], $lookup, $now);
        return ['operation' => $operation, 'nonce' => $nonce, 'key' => $payload['operation_key'], 'lookup' => $lookup, 'intent' => $intent];
    }

    /** @param array<string,mixed> $payload
     *  @return array{outcome:string,result:array<string,mixed>}
     */
    public static function response(array $payload, PeerPolicy $peer, string $operation, string $nonce,
        string $requestDigest, int $now): array
    {
        ModelValues::exactKeys($payload, ['contract','kind','issuer','audience','operation','nonce','request_sha256',
            'issued_at','expires_at','outcome','result']);
        if ($payload['contract'] !== DelegatedContext::CONTRACT || $payload['kind'] !== SignedEnvelope::RESPONSE
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || $payload['operation'] !== $operation || $payload['nonce'] !== $nonce
            || $payload['request_sha256'] !== $requestDigest
            || !is_string($payload['outcome']) || !in_array($payload['outcome'], ['ok','refused','unknown'], true)
            || !is_array($payload['result'])
        ) {
            throw new ModelViolation('pf_response_mismatch');
        }
        DelegatedContext::fresh($payload['issued_at'], $payload['expires_at'], $now);
        $result = $payload['result'];
        if ($payload['outcome'] !== 'ok') {
            ModelValues::exactKeys($result, ['reason']);
            if (!is_string($result['reason']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $result['reason']) !== 1) {
                throw new ModelViolation('pf_response_mismatch');
            }
        } else {
            $state = $result['state'] ?? null;
            if (!in_array($state, ['reserved','released','expired','confirmed','not_found'], true)) {
                throw new ModelViolation('pf_response_mismatch');
            }
            ModelValues::exactKeys($result, $state === 'confirmed' ? ['state','receipt'] : ['state']);
            if ($state === 'confirmed' && !is_array($result['receipt'])) {
                throw new ModelViolation('pf_response_mismatch');
            }
        }
        return ['outcome' => $payload['outcome'], 'result' => $result];
    }

    public static function operation(string $operation, string $lookup): void
    {
        if (!in_array($operation, ['reserve','confirm','release','lookup'], true)
            || ($operation === 'lookup' ? !in_array($lookup, ['reserve','confirm','release'], true) : $lookup !== '')
        ) {
            throw new ModelViolation('pf_invalid_operation');
        }
    }
}

<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Dedicated read delegation and fresh request-bound replies, separate from economic operations. */
final class SnapshotTransport
{
    private const FIELDS = ['operation', 'read_id', 'member_faluss_id', 'snapshot_id', 'cursor'];

    /** @param array<string,mixed> $fields */
    public static function fields(array $fields): void
    {
        ModelValues::exactKeys($fields, self::FIELDS);
        ModelValues::uuid($fields['read_id']);
        ModelValues::uuid($fields['member_faluss_id']);
        if (!in_array($fields['operation'], ['start', 'page', 'finish'], true)) {
            throw new ModelViolation('pf_snapshot_request_mismatch');
        }
        if ($fields['operation'] === 'start') {
            if ($fields['snapshot_id'] !== '' || $fields['cursor'] !== '') {
                throw new ModelViolation('pf_snapshot_request_mismatch');
            }
        } else {
            ModelValues::uuid($fields['snapshot_id']);
            if ($fields['operation'] === 'page') {
                ModelValues::uuid($fields['cursor']);
            } elseif ($fields['cursor'] !== '') {
                throw new ModelViolation('pf_snapshot_request_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $fields
     * @return array{wire:string,nonce:string} */
    public static function sealRequest(array $fields, string $key, string $keyId, int $expires): array
    {
        self::fields($fields);
        ModelValues::keyHash($key);
        $now = time();
        $nonce = bin2hex(random_bytes(32));
        $base = ['contract' => SnapshotDocument::CONTRACT, 'issuer' => 'fixture.fans', 'audience' => 'fixture.hub',
            'nonce' => $nonce, 'issued_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', min($now + 60, $expires))] + $fields;
        DelegatedContext::fresh($base['issued_at'], $base['expires_at'], $now);
        $context = ['kind' => SignedEnvelope::SNAPSHOT_CONTEXT, 'key_sha256' => hash('sha256', $key)] + $base;
        $payload = ['kind' => SignedEnvelope::SNAPSHOT_REQUEST, 'read_key' => $key,
            'context' => SignedEnvelope::seal(SignedEnvelope::SNAPSHOT_CONTEXT, CanonicalJson::encode($context), $keyId)] + $base;
        return ['nonce' => $nonce, 'wire' => CanonicalJson::encode(SignedEnvelope::seal(
            SignedEnvelope::SNAPSHOT_REQUEST, CanonicalJson::encode($payload), $keyId))];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,string> */
    public static function request(array $payload, PeerPolicy $peer, int $now): array
    {
        ModelValues::exactKeys($payload, array_merge(self::FIELDS, ['contract', 'kind', 'issuer', 'audience',
            'nonce', 'issued_at', 'expires_at', 'read_key', 'context']));
        $fields = array_intersect_key($payload, array_flip(self::FIELDS));
        self::fields($fields);
        if ($payload['contract'] !== SnapshotDocument::CONTRACT || $payload['kind'] !== SignedEnvelope::SNAPSHOT_REQUEST
            || $peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub'
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience || !is_array($payload['context'])
        ) {
            throw new ModelViolation('pf_snapshot_request_mismatch');
        }
        $peer->allow('pf.snapshot');
        $peer->allow('pf.context.delegate');
        DelegatedContext::nonce($payload['nonce']);
        ModelValues::keyHash($payload['read_key']);
        DelegatedContext::fresh($payload['issued_at'], $payload['expires_at'], $now);
        $context = SignedEnvelope::open(SignedEnvelope::SNAPSHOT_CONTEXT, $payload['context'], $peer, $now);
        $expected = ['kind' => SignedEnvelope::SNAPSHOT_CONTEXT, 'key_sha256' => hash('sha256', $payload['read_key'])]
            + array_diff_key($payload, array_flip(['kind', 'read_key', 'context']));
        if (CanonicalJson::encode($context) !== CanonicalJson::encode($expected)) {
            throw new ModelViolation('pf_snapshot_context_mismatch');
        }
        return $fields + ['nonce' => $payload['nonce'], 'read_key' => $payload['read_key']];
    }

    /** @param array<string,mixed> $payload
     * @param array<string,mixed> $fields
     * @return array{outcome:string,result:array<string,mixed>} */
    public static function response(array $payload, PeerPolicy $peer, array $fields, string $nonce, string $digest, int $now): array
    {
        ModelValues::exactKeys($payload, ['contract', 'kind', 'issuer', 'audience', 'read_id', 'operation',
            'member_faluss_id', 'nonce', 'request_sha256', 'issued_at', 'expires_at', 'outcome', 'result']);
        if ($payload['contract'] !== SnapshotDocument::CONTRACT || $payload['kind'] !== SignedEnvelope::SNAPSHOT_RESPONSE
            || $peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || $payload['read_id'] !== $fields['read_id'] || $payload['operation'] !== $fields['operation']
            || $payload['member_faluss_id'] !== $fields['member_faluss_id'] || $payload['nonce'] !== $nonce
            || $payload['request_sha256'] !== $digest || !in_array($payload['outcome'], ['ok', 'refused', 'unknown'], true)
            || !is_array($payload['result'])
        ) {
            throw new ModelViolation('pf_snapshot_response_mismatch');
        }
        DelegatedContext::fresh($payload['issued_at'], $payload['expires_at'], $now);
        if ($payload['outcome'] !== 'ok') {
            ModelValues::exactKeys($payload['result'], ['reason']);
            if (!is_string($payload['result']['reason']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $payload['result']['reason']) !== 1) {
                throw new ModelViolation('pf_snapshot_response_mismatch');
            }
        }
        return ['outcome' => $payload['outcome'], 'result' => $payload['result']];
    }
}

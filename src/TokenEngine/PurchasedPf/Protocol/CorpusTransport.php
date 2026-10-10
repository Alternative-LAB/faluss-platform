<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Dedicated global owner read; never a member delegation or economic operation. */
final class CorpusTransport
{
    // The signed payload limit stays 4 MiB; the wire additionally contains canonical base64 and envelope metadata.
    public const MAX_REQUEST_WIRE = 66048;
    public const MAX_RESPONSE_WIRE = 5593088;
    private const FIELDS = ['operation','read_id','origin_id','policy_version','corpus_id','cursor'];

    /** @param array<string,mixed> $fields */
    public static function fields(array $fields): void
    {
        ModelValues::exactKeys($fields,self::FIELDS);
        ModelValues::uuid($fields['read_id']); ModelValues::uuid($fields['origin_id']); ModelValues::version($fields['policy_version']);
        if (!in_array($fields['operation'],['start','lookup','page','finish'],true)) { throw new ModelViolation('pf_corpus_request_mismatch'); }
        if (in_array($fields['operation'],['start','lookup'],true)) {
            if ($fields['corpus_id'] !== '' || $fields['cursor'] !== '') { throw new ModelViolation('pf_corpus_request_mismatch'); }
        } else {
            ModelValues::uuid($fields['corpus_id']);
            if ($fields['operation'] === 'page') { ModelValues::uuid($fields['cursor']); }
            elseif ($fields['cursor'] !== '') { throw new ModelViolation('pf_corpus_request_mismatch'); }
        }
    }

    /** @param array<string,mixed> $fields
     * @return array{wire:string,nonce:string} */
    public static function sealRequest(array $fields, string $key, string $keyId, int $expires): array
    {
        self::fields($fields); ModelValues::keyHash($key); $now = time(); $nonce = bin2hex(random_bytes(32));
        $base = ['contract' => RankingCorpusDocument::CONTRACT,'issuer' => 'fixture.fans','audience' => 'fixture.hub',
            'nonce' => $nonce,'issued_at' => gmdate('Y-m-d\TH:i:s\Z',$now),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z',min($now + 60,$expires))] + $fields;
        DelegatedContext::fresh($base['issued_at'],$base['expires_at'],$now);
        $context = ['kind' => SignedEnvelope::CORPUS_CONTEXT,'key_sha256' => hash('sha256',$key)] + $base;
        $payload = ['kind' => SignedEnvelope::CORPUS_REQUEST,'read_key' => $key,
            'context' => SignedEnvelope::seal(SignedEnvelope::CORPUS_CONTEXT,CanonicalJson::encode($context),$keyId)] + $base;
        $wire = CanonicalJson::encode(SignedEnvelope::seal(SignedEnvelope::CORPUS_REQUEST,CanonicalJson::encode($payload),$keyId));
        if (strlen($wire) > self::MAX_REQUEST_WIRE) { throw new ModelViolation('pf_payload_size'); }
        return ['wire' => $wire,'nonce' => $nonce];
    }

    /** The caller verifies the outer CORPUS_REQUEST signature before calling this validator.
     * @param array<string,mixed> $payload
     * @return array<string,string> */
    public static function request(array $payload, PeerPolicy $peer, int $now): array
    {
        ModelValues::exactKeys($payload,array_merge(self::FIELDS,['contract','kind','issuer','audience','nonce','issued_at','expires_at','read_key','context']));
        $fields = array_intersect_key($payload,array_flip(self::FIELDS)); self::fields($fields);
        if ($payload['contract'] !== RankingCorpusDocument::CONTRACT || $payload['kind'] !== SignedEnvelope::CORPUS_REQUEST
            || $peer->node !== 'fixture.fans' || $peer->audience !== 'fixture.hub'
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || !is_array($payload['context']) || !is_string($payload['read_key'])) { throw new ModelViolation('pf_corpus_request_mismatch'); }
        $peer->allow('pf.ranking.corpus'); DelegatedContext::nonce($payload['nonce']); ModelValues::keyHash($payload['read_key']);
        DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],$now);
        $context = SignedEnvelope::open(SignedEnvelope::CORPUS_CONTEXT,$payload['context'],$peer,$now);
        $expected = ['kind' => SignedEnvelope::CORPUS_CONTEXT,'key_sha256' => hash('sha256',$payload['read_key'])]
            + array_diff_key($payload,array_flip(['kind','read_key','context']));
        if (CanonicalJson::encode($context) !== CanonicalJson::encode($expected)) { throw new ModelViolation('pf_corpus_context_mismatch'); }
        return $fields + ['nonce' => $payload['nonce'],'read_key' => $payload['read_key']];
    }

    /** A page is authenticated here; exhaustive cross-page completion remains the durable inbox's responsibility.
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $fields
     * @return array{outcome:string,result:array<string,mixed>} */
    public static function response(array $payload, PeerPolicy $peer, array $fields, string $nonce, string $digest, int $now): array
    {
        self::fields($fields); DelegatedContext::nonce($nonce); RankingValues::digest($digest);
        ModelValues::exactKeys($payload,array_merge(self::FIELDS,['contract','kind','issuer','audience','nonce','request_sha256','issued_at','expires_at','outcome','result']));
        if ($payload['contract'] !== RankingCorpusDocument::CONTRACT || $payload['kind'] !== SignedEnvelope::CORPUS_RESPONSE
            || $peer->node !== 'fixture.hub' || $peer->audience !== 'fixture.fans'
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || CanonicalJson::encode(array_intersect_key($payload,array_flip(self::FIELDS))) !== CanonicalJson::encode($fields)
            || $payload['nonce'] !== $nonce || $payload['request_sha256'] !== $digest
            || !in_array($payload['outcome'],['ok','refused','unknown'],true) || !is_array($payload['result'])) { throw new ModelViolation('pf_corpus_response_mismatch'); }
        $peer->allow('pf.ranking.corpus'); DelegatedContext::fresh($payload['issued_at'],$payload['expires_at'],$now);
        $result = $payload['result'];
        if ($payload['outcome'] !== 'ok') {
            ModelValues::exactKeys($result,['reason']);
            if (!is_string($result['reason']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$result['reason']) !== 1) { throw new ModelViolation('pf_corpus_response_mismatch'); }
        } else { self::result($result,$fields); }
        return ['outcome' => $payload['outcome'],'result' => $result];
    }

    /** @param array<string,mixed> $result
     * @param array<string,mixed> $fields */
    private static function result(array $result, array $fields): void
    {
        if ($fields['operation'] === 'lookup' && $result === ['state' => 'absent']) { return; }
        if ($fields['operation'] === 'finish') {
            ModelValues::exactKeys($result,['state','manifest','manifest_sha256','verified_at']);
            if ($result['state'] !== 'current' || !is_array($result['manifest'])) { throw new ModelViolation('pf_corpus_response_mismatch'); }
            $manifest = $result['manifest']; RankingCorpusDocument::manifest($manifest,$fields['origin_id'],$fields['policy_version']);
            RankingValues::utc($result['verified_at']);
            if ($manifest['corpus_id'] !== $fields['corpus_id'] || $result['verified_at'] < $manifest['created_at']
                || $result['manifest_sha256'] !== hash('sha256',CanonicalJson::encode($manifest))) { throw new ModelViolation('pf_corpus_response_mismatch'); }
            return;
        }
        ModelValues::exactKeys($result,['state','page']);
        if ($result['state'] !== ($fields['operation'] === 'page' ? 'page' : 'materialized') || !is_array($result['page'])
            || !is_array($result['page']['manifest'] ?? null)) { throw new ModelViolation('pf_corpus_response_mismatch'); }
        $page = $result['page']; $manifest = $page['manifest'];
        RankingCorpusDocument::manifest($manifest,$fields['origin_id'],$fields['policy_version']); RankingCorpusDocument::page($page,$manifest);
        if ($fields['operation'] === 'page') {
            if ($manifest['corpus_id'] !== $fields['corpus_id'] || $page['cursor'] !== $fields['cursor']) { throw new ModelViolation('pf_corpus_response_mismatch'); }
        } elseif ($page['page_index'] !== '0') { throw new ModelViolation('pf_corpus_response_mismatch'); }
    }
}

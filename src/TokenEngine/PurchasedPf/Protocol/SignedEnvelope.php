<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\Federation\FederationBridge;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Domain-separated Ed25519 through Federation's existing public primitive facade. */
final class SignedEnvelope
{
    public const RECEIPT = 'faluss.hub-purchased-pf.receipt/1';
    public const CONTEXT = 'faluss.hub-purchased-pf.context/1';
    public const REQUEST = 'faluss.hub-purchased-pf.request/1';
    public const RESPONSE = 'faluss.hub-purchased-pf.response/1';
    public const SNAPSHOT_CONTEXT = 'faluss.hub-purchased-pf.snapshot-context/1';
    public const SNAPSHOT_REQUEST = 'faluss.hub-purchased-pf.snapshot-request/1';
    public const SNAPSHOT_RESPONSE = 'faluss.hub-purchased-pf.snapshot-response/1';
    public const RANKING_RECEIPT = 'faluss.hub-purchased-pf.receipt/2';
    public const RANKING_CONTEXT = 'faluss.hub-purchased-pf.context/2';
    public const RANKING_REQUEST = 'faluss.hub-purchased-pf.request/2';
    public const RANKING_RESPONSE = 'faluss.hub-purchased-pf.response/2';
    public const RANKING_SNAPSHOT_CONTEXT = 'faluss.hub-purchased-pf.snapshot-context/2';
    public const RANKING_SNAPSHOT_REQUEST = 'faluss.hub-purchased-pf.snapshot-request/2';
    public const RANKING_SNAPSHOT_RESPONSE = 'faluss.hub-purchased-pf.snapshot-response/2';

    public const CORPUS_CONTEXT = 'faluss.hub-purchased-pf.corpus-context/1';
    public const CORPUS_REQUEST = 'faluss.hub-purchased-pf.corpus-request/1';
    public const CORPUS_RESPONSE = 'faluss.hub-purchased-pf.corpus-response/1';

    public const BARRIER_CONTEXT = 'faluss.hub-purchased-pf.barrier-context/1';
    public const BARRIER_REQUEST = 'faluss.hub-purchased-pf.barrier-request/1';
    public const BARRIER_RESPONSE = 'faluss.hub-purchased-pf.barrier-response/1';

    /** @return array<string,string> */
    public static function seal(string $domain, string $bytes, string $key): array
    {
        CanonicalJson::object($bytes, self::limit($domain));
        $digest = hash('sha256', $bytes);
        $signature = FederationBridge::invoke('Faluss_Federation_Crypto', 'sign', self::message($domain, $key, $digest));
        if (!is_string($signature)) {
            throw new ModelViolation('pf_signature_unavailable');
        }
        return ['payload_base64url' => self::encode($bytes), 'key_id' => $key,
            'payload_sha256' => $digest, 'signature_base64url' => $signature];
    }

    /** @param array<array-key,mixed> $envelope
     *  @return array<string,mixed>
     */
    public static function open(string $domain, array $envelope, PeerPolicy $peer, int $now): array
    {
        ModelValues::exactKeys($envelope, ['payload_base64url', 'key_id', 'payload_sha256', 'signature_base64url']);
        foreach ($envelope as $value) {
            if (!is_string($value)) {
                throw new ModelViolation('pf_invalid_envelope');
            }
        }
        $key = PeerPolicy::keyId($envelope['key_id']);
        $public = $peer->publicKey($key, $now);
        $bytes = self::decode($envelope['payload_base64url'], self::limit($domain));
        $digest = hash('sha256', $bytes);
        if (!hash_equals($digest, $envelope['payload_sha256'])
            || FederationBridge::invoke('Faluss_Federation_Crypto', 'verify', self::message($domain, $key, $digest),
                $envelope['signature_base64url'], $public) !== true
        ) {
            throw new ModelViolation('pf_signature_invalid');
        }
        return CanonicalJson::object($bytes, self::limit($domain));
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $encoded, int $limit = 32768): string
    {
        if (strlen($encoded) > (int) ceil($limit * 4 / 3) || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new ModelViolation('pf_invalid_envelope');
        }
        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (!is_string($bytes) || self::encode($bytes) !== $encoded || strlen($bytes) > $limit) {
            throw new ModelViolation('pf_invalid_envelope');
        }
        return $bytes;
    }

    private static function message(string $domain, string $key, string $digest): string
    {
        if (!in_array($domain, [self::RECEIPT, self::CONTEXT, self::REQUEST, self::RESPONSE,
            self::SNAPSHOT_CONTEXT, self::SNAPSHOT_REQUEST, self::SNAPSHOT_RESPONSE,
            self::RANKING_RECEIPT,self::RANKING_CONTEXT,self::RANKING_REQUEST,self::RANKING_RESPONSE,
            self::RANKING_SNAPSHOT_CONTEXT,self::RANKING_SNAPSHOT_REQUEST,self::RANKING_SNAPSHOT_RESPONSE,
            self::CORPUS_CONTEXT,self::CORPUS_REQUEST,self::CORPUS_RESPONSE,
            self::BARRIER_CONTEXT,self::BARRIER_REQUEST,self::BARRIER_RESPONSE], true)) {
            throw new ModelViolation('pf_invalid_domain');
        }
        return $domain . "\nkid:" . PeerPolicy::keyId($key) . "\nsha256:" . $digest;
    }

    private static function limit(string $domain): int
    {
        return match ($domain) {
            self::REQUEST, self::RESPONSE, self::SNAPSHOT_REQUEST => 49152,
            self::RANKING_REQUEST, self::RANKING_RESPONSE => 49152,
            self::BARRIER_REQUEST, self::BARRIER_RESPONSE => 49152,
            self::RANKING_SNAPSHOT_REQUEST, self::CORPUS_REQUEST => 49152,
            self::SNAPSHOT_RESPONSE => 262144,
            self::RANKING_SNAPSHOT_RESPONSE => 1048576,
            self::CORPUS_RESPONSE => 4194304,
            default => 32768,
        };
    }
}

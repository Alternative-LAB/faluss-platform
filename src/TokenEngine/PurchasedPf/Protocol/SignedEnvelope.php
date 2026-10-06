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
            self::SNAPSHOT_CONTEXT, self::SNAPSHOT_REQUEST, self::SNAPSHOT_RESPONSE], true)) {
            throw new ModelViolation('pf_invalid_domain');
        }
        return $domain . "\nkid:" . PeerPolicy::keyId($key) . "\nsha256:" . $digest;
    }

    private static function limit(string $domain): int
    {
        return match ($domain) {
            self::REQUEST, self::RESPONSE, self::SNAPSHOT_REQUEST => 49152,
            self::SNAPSHOT_RESPONSE => 262144,
            default => 32768,
        };
    }
}

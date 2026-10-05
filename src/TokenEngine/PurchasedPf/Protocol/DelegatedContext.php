<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Public DTO for a trusted server's explicit delegation, never a browser identity assertion. */
final class DelegatedContext
{
    public const CONTRACT = 'fans.hub-purchased-pf/0.2.0';

    /** @param array<string,mixed> $payload */
    public static function validate(array $payload, PeerPolicy $peer, string $operation, string $nonce,
        string $key, string $lookupOperation, int $now): AttributionIntent
    {
        ModelValues::exactKeys($payload, ['contract', 'kind', 'issuer', 'audience', 'operation', 'nonce',
            'issued_at', 'expires_at', 'key_sha256', 'lookup_operation', 'intent']);
        $peer->allow('pf.context.delegate');
        $peer->allow('pf.' . $operation);
        if ($payload['contract'] !== self::CONTRACT || $payload['kind'] !== SignedEnvelope::CONTEXT
            || $payload['issuer'] !== $peer->node || $payload['audience'] !== $peer->audience
            || $payload['operation'] !== $operation || $payload['nonce'] !== $nonce
            || $payload['key_sha256'] !== ModelValues::keyHash($key) || $payload['lookup_operation'] !== $lookupOperation
            || !is_array($payload['intent'])
        ) {
            throw new ModelViolation('pf_context_mismatch');
        }
        self::fresh($payload['issued_at'], $payload['expires_at'], $now);
        $intent = AttributionIntent::fromArray($payload['intent']);
        if ($intent->values['client_authority'] !== $peer->node) {
            throw new ModelViolation('pf_context_mismatch');
        }
        return $intent;
    }

    public static function fresh(mixed $issued, mixed $expires, int $now): void
    {
        $start = strtotime(ModelValues::utc($issued));
        $end = strtotime(ModelValues::utc($expires));
        if ($start > $now + 5 || $end <= $now || $end <= $start || $end - $start > 60) {
            throw new ModelViolation('pf_context_expired');
        }
    }

    public static function nonce(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ModelViolation('pf_invalid_nonce');
        }
        return $value;
    }
}

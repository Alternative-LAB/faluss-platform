<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Explicit 0.3 delegation. Validation alone never admits a context or performs a debit. */
final class RankedDelegation
{
    /** @param array<string,mixed> $payload */
    public static function validate(array $payload, PeerPolicy $peer, string $operation, string $nonce,
        string $key, string $lookupOperation, int $now): RankedIntent
    {
        ModelValues::exactKeys($payload,['contract','kind','issuer','audience','operation','nonce','issued_at','expires_at','key_sha256','lookup_operation','intent']);
        DelegatedContext::nonce($nonce);
        if ($payload['contract'] !== RankedIntent::CONTRACT || $payload['kind'] !== SignedEnvelope::RANKING_CONTEXT || !is_array($payload['intent'])) { throw new ModelViolation('pf_ranking_context_mismatch'); }
        $intent = RankedIntent::fromArray($payload['intent']);
        // Reuse the existing freshness, operation, audience, identity and key protections without relaxing its wire shape.
        $legacy = $payload; $legacy['contract'] = DelegatedContext::CONTRACT; $legacy['kind'] = SignedEnvelope::CONTEXT; $legacy['intent'] = $intent->base->values;
        DelegatedContext::validate($legacy,$peer,$operation,$nonce,$key,$lookupOperation,$now);
        return $intent;
    }
}

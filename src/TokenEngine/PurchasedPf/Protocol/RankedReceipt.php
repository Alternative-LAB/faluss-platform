<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Private historical 0.3 receipt; not a current corrected balance, score or public rank. */
final class RankedReceipt
{
    /** @param array<string,mixed> $payload */
    public static function validate(array $payload, string $issuer, string $audience, RankedIntent $intent): void
    {
        $extra = ['ordering_epoch','consumption_order','ranking_context','context_sha256'];
        $old = array_diff_key($payload,array_flip($extra));
        if (($payload['contract'] ?? '') !== RankedIntent::CONTRACT || ($payload['kind'] ?? '') !== SignedEnvelope::RANKING_RECEIPT
            || !is_array($payload['ranking_context'] ?? null)) { throw new ModelViolation('pf_ranking_receipt_mismatch'); }
        ModelValues::uuid($payload['ordering_epoch'] ?? null); ModelValues::integer($payload['consumption_order'] ?? null,true);
        RankingValues::utc($payload['confirmed_at'] ?? null);
        RankingValues::digest($payload['context_sha256'] ?? null);
        if ($payload['context_sha256'] !== $intent->values['context_sha256']
            || CanonicalJson::encode(RankingContext::validate($payload['ranking_context'])) !== CanonicalJson::encode($intent->values['ranking_context'])) { throw new ModelViolation('pf_ranking_receipt_mismatch'); }
        RankingContext::atConfirmation($payload['ranking_context'],$payload['confirmed_at']);
        $old['contract'] = DelegatedContext::CONTRACT; $old['kind'] = SignedEnvelope::RECEIPT;
        $old['confirmed_at'] = str_replace(' ','T',substr($payload['confirmed_at'],0,19)) . 'Z';
        PrivateReceipt::validate($old,$issuer,$audience,$intent->base);
        CanonicalJson::object(CanonicalJson::encode($payload));
    }
}

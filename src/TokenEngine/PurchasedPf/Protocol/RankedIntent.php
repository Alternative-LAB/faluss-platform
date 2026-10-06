<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** New immutable intention; the old six-field intention never gains implicit ranking authority. */
final class RankedIntent
{
    public const CONTRACT = 'fans.hub-purchased-pf/0.3.0';

    /** @param array<string,mixed> $values */
    private function __construct(public readonly AttributionIntent $base, public readonly array $values) {}

    /** @param array<array-key,mixed> $input */
    public static function fromArray(array $input): self
    {
        $fields = ['attribution_id','client_authority','member_faluss_id','creator_faluss_id','purchased_pf','policy_version'];
        ModelValues::exactKeys($input,array_merge($fields,['ranking_context','context_sha256']));
        $base = AttributionIntent::fromArray(array_intersect_key($input,array_flip($fields)));
        if (!is_array($input['ranking_context'])) { throw new ModelViolation('pf_ranking_invalid_context'); }
        $context = RankingContext::validate($input['ranking_context']);
        if ($context['policy_version'] !== $base->values['policy_version']
            || !hash_equals(RankingContext::fingerprint($context),RankingValues::digest($input['context_sha256']))) { throw new ModelViolation('pf_ranking_context_mismatch'); }
        return new self($base,$base->values + ['ranking_context' => $context,'context_sha256' => $input['context_sha256']]);
    }

    public function fingerprint(): string { return hash('sha256',CanonicalJson::encode($this->values)); }
}

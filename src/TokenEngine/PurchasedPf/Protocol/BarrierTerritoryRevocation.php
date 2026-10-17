<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Approved 1.2 syntax; the stored admission must still be checked under primary locks. */
final class BarrierTerritoryRevocation
{
    public const REASON = 'territory_admission_revoked';

    /** @param array<array-key,mixed> $input
     * @return array<string,string> */
    public static function reference(array $input): array
    {
        if (($input['reason'] ?? null) !== self::REASON) { return BarrierCompletion::reference($input); }
        ModelValues::exactKeys($input,['barrier_key','version','content_sha256','reason']);
        return ['barrier_key' => RankingValues::digest($input['barrier_key']),'version' => ModelValues::integer($input['version'],true),
            'content_sha256' => RankingValues::digest($input['content_sha256']),'reason' => self::REASON];
    }

    /** @param array<array-key,mixed> $input */
    public static function requireTerritorialAdmission(array $input): void
    {
        $descriptor = RankingBarrier::descriptor($input); $content = $descriptor['content'];
        if ($content['kind'] !== 'admission' || $content['territory_policy_revision'] === ''
            || $content['territory_admission_revision'] === '0') {
            throw new ModelViolation('pf_barrier_territory_requires_admission');
        }
    }
}

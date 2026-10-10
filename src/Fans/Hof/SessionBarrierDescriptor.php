<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingBarrier;

/** Server-owned frozen B2 rules only; neither browser fields nor editorial text enter Hub. */
final class SessionBarrierDescriptor
{
    /** @param array<string,string> $session
     * @return array<string,mixed> */
    public static function compose(array $session, string $origin, ?string $territoryPolicy): array
    {
        ModelValues::uuid($origin); ModelValues::uuid($session['session_id'] ?? null);
        if (($session['policy_version'] ?? '') !== RankingPolicy::VERSION
            || !in_array($session['state'] ?? '',['opening','open','closing','closed','cancelled','suspended'],true)
            || ($session['frozen_sha256'] ?? '') !== SessionRules::digest($session)) {
            throw new ModelViolation('hof_frozen_session_required');
        }
        $policy = $territoryPolicy ?? '';
        if ($session['scope'] === 'international' ? $policy !== '' : $policy === '') { throw new ModelViolation('hof_reviewed_territory_required'); }
        $content = ['kind' => 'session','origin_id' => $origin,'object_id' => $session['session_id'],'subject_id' => '',
            'version' => ModelValues::integer($session['barrier_version'] ?? null,true),'policy_version' => RankingPolicy::VERSION,
            'rules_revision' => ModelValues::integer($session['rules_version'] ?? null,true),'rules_sha256' => $session['frozen_sha256'],
            'starts_at' => $session['starts_at'],'ends_at' => $session['ends_at'],'scope' => $session['scope'],
            'territory_policy_revision' => $policy,'country' => $session['country'],'territory_ref' => $session['territory_ref']];
        return RankingBarrier::descriptor(['content' => $content,'valid_from' => $session['starts_at'],'valid_until' => $session['ends_at']]);
    }
}

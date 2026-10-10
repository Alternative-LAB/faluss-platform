<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Pure private session dimensions from the same complete owner corpus. No consumption, admission or public winner. */
final class RankingSessionProjection
{
    /** Caller must first authenticate the current exhaustive generation through the owner inbox.
     * @param array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string} $verified
     * @return array{source:array<string,mixed>,sessions:list<array<string,mixed>>} */
    public static function build(array $verified): array
    {
        // Validate the whole corpus before selecting dimensions; a partial subset is never its own manifest.
        $base = RankingCorpusProjection::build($verified); $groups = [];
        foreach ($verified['facts'] as $fact) {
            foreach ($fact['ranking_context']['sessions'] as $session) {
                $id = $session['session_id'];
                $frozen = array_intersect_key($session,array_flip(['session_id','rules_revision','rules_sha256',
                    'starts_at','ends_at','scope','territory_policy_revision','country','territory_ref']));
                if (isset($groups[$id]) && CanonicalJson::encode($groups[$id]['frozen']) !== CanonicalJson::encode($frozen)) {
                    throw new ModelViolation('hof_conflicting_frozen_session');
                }
                $groups[$id] ??= ['frozen' => $frozen,'facts' => []];
                $groups[$id]['facts'][] = $fact;
            }
        }
        ksort($groups,SORT_STRING); $sessions = [];
        foreach ($groups as $group) {
            $sources = [];
            foreach ($group['facts'] as $fact) {
                $member = $fact['member_faluss_id'];
                $sources[$member] ??= ['member_faluss_id' => $member,'revision' => $verified['manifest']['revision'],
                    'ordering_epoch' => $verified['manifest']['ordering_epoch'],'attributions' => []];
                $sources[$member]['attributions'][] = array_intersect_key($fact,array_flip(['attribution_id','creator_faluss_id',
                    'consumption_id','ledger_entry_uuid','net_pf','confirmed_at','consumption_order'])) + ['original_pf' => $fact['purchased_pf']];
            }
            $sessions[] = $group['frozen'] + RankingFacts::build(array_values($sources));
        }
        return ['source' => $base['source'],'sessions' => $sessions];
    }
}

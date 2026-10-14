<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\RankingCorpusDocument;

/** Pure projection of a complete, previously authenticated owner generation. No storage or public delivery. */
final class RankingCorpusProjection
{
    /** Signature, request binding and primary fence must first pass in the owner inbox.
     * @param array{manifest:array<string,mixed>,facts:list<array<string,mixed>>,verified_at:string} $verified
     * @return array<string,mixed> */
    public static function build(array $verified): array
    {
        ModelValues::exactKeys($verified,['manifest','facts','verified_at']);
        $manifest = $verified['manifest']; $facts = $verified['facts'];
        RankingCalendar::utc($verified['verified_at']); RankingCorpusDocument::complete($manifest,$facts);
        if ($manifest['policy_version'] !== RankingPolicy::VERSION || $verified['verified_at'] < $manifest['created_at']) {
            throw new ModelViolation('hof_projection_source_mismatch');
        }
        $categories = []; $months = [];
        foreach (RankingPolicy::CATEGORIES as $category) { $categories[$category] = []; }
        foreach ($facts as $fact) {
            $categories[RankingPolicy::category($fact['ranking_context']['creator_category'])][] = $fact;
            $month = RankingCalendar::monthOf($fact['confirmed_at']); $months[$month][] = $fact;
        }
        foreach ($categories as $category => $selected) { $categories[$category] = self::rank($selected,$manifest); }
        ksort($months,SORT_STRING);
        foreach ($months as $month => $selected) {
            $bounds = RankingCalendar::month($month);
            $months[$month] = $bounds + self::statement($selected);
        }
        return ['source' => array_intersect_key($manifest,array_flip(['corpus_id','origin_id','policy_version','ordering_epoch','epoch','revision','full_sha256']))
                + ['verified_at' => $verified['verified_at']],
            'general' => self::rank($facts,$manifest),'categories' => $categories,'months' => $months];
    }

    /** @param list<array<string,mixed>> $facts
     * @param array<string,mixed> $manifest
     * @return array{fans:list<array<string,string>>,creators:list<array<string,string>>} */
    private static function rank(array $facts, array $manifest): array
    {
        $sources = [];
        foreach ($facts as $fact) {
            $member = $fact['member_faluss_id'];
            $sources[$member] ??= ['member_faluss_id' => $member,'revision' => $manifest['revision'],
                'ordering_epoch' => $manifest['ordering_epoch'],'attributions' => []];
            $sources[$member]['attributions'][] = array_intersect_key($fact,array_flip(['attribution_id','creator_faluss_id','consumption_id',
                'ledger_entry_uuid','net_pf','confirmed_at','consumption_order'])) + ['original_pf' => $fact['purchased_pf']];
        }
        return RankingFacts::build(array_values($sources));
    }

    /** Monthly statements are private net totals, not monthly public ranks or financial indicators.
     * @param list<array<string,mixed>> $facts
     * @return array{fans:list<array{faluss_id:string,points:string}>,creators:list<array{faluss_id:string,points:string}>} */
    private static function statement(array $facts): array
    {
        $fans = $creators = [];
        foreach ($facts as $fact) {
            self::add($fans,$fact['member_faluss_id'],(int) $fact['net_pf']);
            self::add($creators,$fact['creator_faluss_id'],(int) $fact['net_pf']);
        }
        return ['fans' => self::statementRows($fans),'creators' => self::statementRows($creators)];
    }

    /** @param array<string,int> $totals */
    private static function add(array &$totals, string $id, int $net): void
    {
        $old = $totals[$id] ?? 0;
        if ($old > ModelValues::MAX_INTEGER-$net) { throw new ModelViolation('hof_points_overflow'); }
        $totals[$id] = $old+$net;
    }

    /** @param array<string,int> $totals
     * @return list<array{faluss_id:string,points:string}> */
    private static function statementRows(array $totals): array
    {
        ksort($totals,SORT_STRING); $rows = [];
        foreach ($totals as $id => $points) { $rows[] = ['faluss_id' => $id,'points' => (string) $points]; }
        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Hof;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;

/** Pure rebuild from complete canonical sources. Does not verify signatures or create Hub authority. */
final class RankingFacts
{
    /** Internal source is constructed only AFTER owner proof/completeness validation by a future B3/B4 adapter.
     * @param list<array{member_faluss_id:string,revision:string,ordering_epoch:string,attributions:array<array-key,array<string,string>>}> $sources
     * @return array{fans:list<array<string,string>>,creators:list<array<string,string>>}
     */
    public static function build(array $sources): array
    {
        $latest = $identities = $versions = $memberFacts = [];
        $epoch = null;
        foreach ($sources as $source) {
            ModelValues::exactKeys($source, ['member_faluss_id', 'revision', 'ordering_epoch', 'attributions']);
            $member = ModelValues::uuid($source['member_faluss_id']);
            $revision = (int) ModelValues::integer($source['revision'], true);
            $sourceEpoch = ModelValues::uuid($source['ordering_epoch']);
            if ($epoch !== null && $epoch !== $sourceEpoch) { throw new ModelViolation('hof_conflicting_order_authority'); }
            $epoch = $sourceEpoch;
            if (!array_is_list($source['attributions'])) { throw new ModelViolation('hof_invalid_source'); }
            $rows = [];
            foreach ($source['attributions'] as $row) {
                self::validate($row);
                if ($row['creator_faluss_id'] === $member) { throw new ModelViolation('hof_self_attribution'); }
                $id = $row['attribution_id'];
                if (isset($rows[$id])) { throw new ModelViolation('hof_duplicate_attribution'); }
                $immutable = $row; unset($immutable['net_pf']);
                $hash = hash('sha256', CanonicalJson::encode(['member' => $member, 'fact' => $immutable]));
                if (isset($identities[$id]) && $identities[$id] !== $hash) { throw new ModelViolation('hof_changed_consumption_identity'); }
                $identities[$id] = $hash;
                $memberFacts[$member][$id] = true;
                $rows[$id] = $row;
            }
            ksort($rows, SORT_STRING);
            $hash = hash('sha256', CanonicalJson::encode(array_values($rows)));
            if (isset($versions[$member][$revision]) && $versions[$member][$revision] !== $hash) {
                throw new ModelViolation('hof_conflicting_source_revision');
            }
            $versions[$member][$revision] = $hash;
            if (!isset($latest[$member]) || $latest[$member]['revision'] < $revision) {
                $latest[$member] = ['revision' => $revision, 'hash' => $hash, 'rows' => $rows];
            }
        }
        $consumptions = $entries = $orders = $fans = $creators = [];
        foreach ($latest as $member => $source) {
            if (count($source['rows']) !== count($memberFacts[$member] ?? [])) { throw new ModelViolation('hof_incomplete_source'); }
            foreach ($source['rows'] as $id => $row) {
                self::unique($consumptions, $row['consumption_id'], $id);
                self::unique($entries, $row['ledger_entry_uuid'], $id);
                self::unique($orders, 'order:' . $row['consumption_order'], $id);
                $net = (int) $row['net_pf'];
                if ($net === 0) { continue; }
                self::contribute($fans, $member, $row, $net);
                self::contribute($creators, $row['creator_faluss_id'], $row, $net);
            }
        }
        return ['fans' => self::rank($fans), 'creators' => self::rank($creators)];
    }

    /** @param array<string,string> $row */
    private static function validate(array $row): void
    {
        ModelValues::exactKeys($row, ['attribution_id', 'creator_faluss_id', 'consumption_id', 'ledger_entry_uuid',
            'original_pf', 'net_pf', 'confirmed_at', 'consumption_order']);
        foreach (['attribution_id', 'creator_faluss_id', 'consumption_id', 'ledger_entry_uuid'] as $field) { ModelValues::uuid($row[$field]); }
        $original = (int) ModelValues::integer($row['original_pf'], true);
        $net = (int) ModelValues::integer($row['net_pf']);
        ModelValues::integer($row['consumption_order'], true);
        RankingCalendar::utc($row['confirmed_at']);
        if ($net > $original) { throw new ModelViolation('hof_net_exceeds_original'); }
    }

    /** @param array<string,array<string,string>> $participants
     * @param array<string,string> $fact */
    private static function contribute(array &$participants, string $id, array $fact, int $net): void
    {
        $old = $participants[$id] ?? ['faluss_id' => $id, 'points' => '0', 'reached_at' => $fact['confirmed_at'], 'consumption_order' => $fact['consumption_order']];
        if ((int) $old['points'] > ModelValues::MAX_INTEGER - $net) { throw new ModelViolation('hof_points_overflow'); }
        $old['points'] = (string) ((int) $old['points'] + $net);
        // Positive corrected contributions reach their final sum at the last surviving owner fact.
        if (self::chronology($fact['confirmed_at'], $fact['consumption_order'], $old['reached_at'], $old['consumption_order']) > 0) {
            $old['reached_at'] = $fact['confirmed_at']; $old['consumption_order'] = $fact['consumption_order'];
        }
        $participants[$id] = $old;
    }

    /** @param array<string,array<string,string>> $participants
     * @return list<array<string,string>> */
    private static function rank(array $participants): array
    {
        $rows = array_values($participants);
        usort($rows, static fn (array $a, array $b): int => ((int) $b['points'] <=> (int) $a['points'])
            ?: self::chronology($a['reached_at'], $a['consumption_order'], $b['reached_at'], $b['consumption_order'])
            ?: strcmp($a['faluss_id'], $b['faluss_id']));
        foreach ($rows as $position => &$row) { $row['position'] = (string) ($position + 1); }
        unset($row);
        return $rows;
    }

    private static function chronology(string $leftDate, string $leftOrder, string $rightDate, string $rightOrder): int
    {
        return strcmp($leftDate, $rightDate) ?: ((int) $leftOrder <=> (int) $rightOrder);
    }

    /** @param array<string,string> $seen */
    private static function unique(array &$seen, string $key, string $attribution): void
    {
        if (isset($seen[$key]) && $seen[$key] !== $attribution) { throw new ModelViolation('hof_duplicate_owner_fact'); }
        $seen[$key] = $attribution;
    }
}

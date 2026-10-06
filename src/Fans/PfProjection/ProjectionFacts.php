<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\PfProjection;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\CanonicalJson;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\SnapshotDocument;

/** Mathematical private derivative. No periods, ranks, rewards, balances or consumption operation. */
final class ProjectionFacts
{
    public const CONTRACT = 'fans.purchased-pf.projection/1.0.0';

    /** @param list<array{manifest:array<string,mixed>,rows:list<array<string,string>>}> $snapshots
     * @return array{contract:string,epoch:string,sources:list<array<string,string>>,attributions:list<array<string,string>>,
     *     fans:list<array{faluss_id:string,points:string}>,creators:list<array{faluss_id:string,points:string}>} */
    public static function build(array $snapshots, string $epoch): array
    {
        ModelValues::uuid($epoch);
        $sources = $facts = $consumptions = $entries = $fans = $creators = [];
        foreach ($snapshots as $snapshot) {
            ModelValues::exactKeys($snapshot, ['manifest', 'rows']);
            $manifest = $snapshot['manifest'];
            $member = ModelValues::uuid($manifest['member_faluss_id']);
            SnapshotDocument::manifest($manifest, $member, $epoch);
            SnapshotDocument::complete($manifest, $snapshot['rows']);
            if (isset($sources[$member])) { throw new ModelViolation('pf_projection_duplicate_source'); }
            $sources[$member] = ['member_faluss_id' => $member, 'snapshot_id' => ModelValues::uuid($manifest['snapshot_id']),
                'revision' => ModelValues::integer($manifest['revision'], true), 'full_sha256' => SnapshotDocument::digest($manifest['full_sha256'])];
            $fans[$member] = 0;
            foreach ($snapshot['rows'] as $row) {
                if ($row['kind'] !== 'allocation') { continue; }
                $id = $row['attribution_id'];
                $identity = array_intersect_key($row, array_flip(['attribution_id', 'consumption_id', 'member_faluss_id',
                    'creator_faluss_id', 'client_authority', 'confirmed_at', 'ledger_entry_uuid', 'ledger_fact_sha256', 'attribution_original_pf']));
                $digest = hash('sha256', CanonicalJson::encode($identity));
                self::unique($consumptions, $row['consumption_id'], $id);
                self::unique($entries, $row['ledger_entry_uuid'], $id);
                if (isset($facts[$id]) && $facts[$id]['identity_sha256'] !== $digest) {
                    throw new ModelViolation('pf_projection_identity_conflict');
                }
                $facts[$id] ??= ['attribution_id' => $id, 'consumption_id' => $row['consumption_id'],
                    'ledger_entry_uuid' => $row['ledger_entry_uuid'], 'member_faluss_id' => $member,
                    'creator_faluss_id' => $row['creator_faluss_id'], 'identity_sha256' => $digest, 'points' => '0'];
                $facts[$id]['points'] = (string) self::add((int) $facts[$id]['points'], (int) $row['net_pf']);
            }
        }
        foreach ($facts as $fact) {
            $fans[$fact['member_faluss_id']] = self::add($fans[$fact['member_faluss_id']], (int) $fact['points']);
            $creator = $fact['creator_faluss_id'];
            $creators[$creator] = self::add($creators[$creator] ?? 0, (int) $fact['points']);
        }
        ksort($sources, SORT_STRING);
        ksort($facts, SORT_STRING);
        return ['contract' => self::CONTRACT, 'epoch' => $epoch, 'sources' => array_values($sources),
            'attributions' => array_values($facts), 'fans' => self::points($fans), 'creators' => self::points($creators)];
    }

    private static function add(int $left, int $right): int
    {
        if ($left > ModelValues::MAX_INTEGER - $right) { throw new ModelViolation('pf_projection_overflow'); }
        return $left + $right;
    }

    /** @param array<string,string> $seen */
    private static function unique(array &$seen, string $identity, string $attribution): void
    {
        if (isset($seen[$identity]) && $seen[$identity] !== $attribution) {
            throw new ModelViolation('pf_projection_duplicate_consumption');
        }
        $seen[$identity] = $attribution;
    }

    /** @param array<string,int> $points
     * @return list<array{faluss_id:string,points:string}> */
    private static function points(array $points): array
    {
        ksort($points, SORT_STRING);
        $rows = [];
        foreach ($points as $id => $value) { $rows[] = ['faluss_id' => $id, 'points' => (string) $value]; }
        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Complete member facts, not proof of a global member inventory or a public rank. */
final class RankedSnapshotDocument
{
    public const CONTRACT = 'hub.purchased-pf.snapshot/2.0.0';
    public const PAGE_SIZE = SnapshotDocument::PAGE_SIZE;

    /** @param array<array-key,array<string,mixed>> $rows
     * @return array<string,string> */
    public static function summary(array $rows): array
    {
        if (!array_is_list($rows)) { throw new ModelViolation('pf_snapshot_invalid_row'); }
        $plain = []; $attributions = []; $orders = [];
        foreach ($rows as $row) {
            $plain[] = self::row($row);
            if ($row['kind'] !== 'allocation') { continue; }
            $id = $row['attribution_id']; $ranking = $row['ranking'];
            $digest = hash('sha256',CanonicalJson::encode(['ranking' => $ranking]));
            if (isset($attributions[$id]) && $attributions[$id] !== $digest) { throw new ModelViolation('pf_snapshot_ranking_filiation'); }
            $attributions[$id] = $digest;
            if ($ranking === null) { continue; }
            $key = $ranking['ordering_epoch'] . '|' . $ranking['consumption_order'];
            if (isset($orders[$key]) && $orders[$key]['id'] !== $id) { throw new ModelViolation('pf_snapshot_ranking_duplicate_order'); }
            $orders[$key] = ['id' => $id,'epoch' => $ranking['ordering_epoch'],'order' => (int) $ranking['consumption_order'],'time' => $row['confirmed_at']];
        }
        $epochs = array_unique(array_column($orders,'epoch'));
        if (count($epochs) > 1) { throw new ModelViolation('pf_snapshot_ranking_epoch'); }
        uasort($orders,static fn (array $a,array $b): int => $a['order'] <=> $b['order']);
        $previous = '';
        foreach ($orders as $order) {
            if ($order['time'] < $previous) { throw new ModelViolation('pf_snapshot_ranking_clock'); }
            $previous = $order['time'];
        }
        return SnapshotDocument::summary($plain);
    }

    /** @param array<string,mixed> $row
     * @return array<string,string> */
    public static function row(array $row): array
    {
        $plain = $row;
        if (($row['kind'] ?? null) === 'allocation') {
            if (!array_key_exists('ranking',$row)) { throw new ModelViolation('pf_snapshot_ranking_required'); }
            unset($plain['ranking']);
        }
        foreach ($plain as $value) { if (!is_string($value)) { throw new ModelViolation('pf_snapshot_invalid_row'); } }
        /** @var array<string,string> $plain */
        SnapshotDocument::row($plain);
        if ($plain['kind'] !== 'allocation' || $row['ranking'] === null) { return $plain; }
        $ranking = $row['ranking'];
        if (!is_array($ranking)) { throw new ModelViolation('pf_snapshot_ranking_required'); }
        ModelValues::exactKeys($ranking,['ordering_epoch','consumption_order','ranking_context','context_sha256']);
        ModelValues::uuid($ranking['ordering_epoch']); ModelValues::integer($ranking['consumption_order'],true);
        if (!is_array($ranking['ranking_context'])) { throw new ModelViolation('pf_snapshot_ranking_filiation'); }
        $context = RankingContext::validate($ranking['ranking_context']);
        RankedIntent::fromArray(['attribution_id' => $plain['attribution_id'],'member_faluss_id' => $plain['member_faluss_id'],
            'creator_faluss_id' => $plain['creator_faluss_id'],'client_authority' => $plain['client_authority'],
            'purchased_pf' => $plain['attribution_original_pf'],'policy_version' => $context['policy_version'],
            'ranking_context' => $context,'context_sha256' => $ranking['context_sha256']]);
        RankingContext::atConfirmation($context,$plain['confirmed_at']);
        return $plain;
    }

    /** @param array<string,mixed> $manifest */
    public static function manifest(array $manifest, string $member, string $epoch): void
    {
        if (($manifest['contract'] ?? null) !== self::CONTRACT || !array_key_exists('ordering_epoch',$manifest)) { throw new ModelViolation('pf_snapshot_context_mismatch'); }
        ModelValues::uuid($manifest['ordering_epoch']);
        SnapshotDocument::manifest(self::plainManifest($manifest),$member,$epoch);
    }

    /** @param array<string,mixed> $manifest
     * @param list<array<string,mixed>> $rows */
    public static function complete(array $manifest, array $rows): void
    {
        self::manifest($manifest,$manifest['member_faluss_id'],$manifest['epoch']);
        foreach (self::summary($rows) as $field => $value) {
            if ($manifest[$field] !== $value) { throw new ModelViolation('pf_snapshot_incomplete'); }
        }
        if (!hash_equals($manifest['full_sha256'],hash('sha256',CanonicalJson::encode($rows)))) { throw new ModelViolation('pf_snapshot_digest_mismatch'); }
        foreach ($rows as $row) { self::context($manifest,$row); }
    }

    /** @param array<string,mixed> $page
     * @param array<string,mixed> $manifest */
    public static function page(array $page, array $manifest): void
    {
        self::manifest($manifest,$manifest['member_faluss_id'],$manifest['epoch']);
        if (($page['manifest'] ?? null) !== $manifest || !isset($page['rows']) || !is_array($page['rows']) || !array_is_list($page['rows'])) { throw new ModelViolation('pf_snapshot_incomplete'); }
        $plainRows = [];
        foreach ($page['rows'] as $row) {
            if (!is_array($row)) { throw new ModelViolation('pf_snapshot_invalid_row'); }
            $plainRows[] = self::row($row); self::context($manifest,$row);
        }
        $plain = $page; $plain['manifest'] = self::plainManifest($manifest); $plain['rows'] = $plainRows;
        SnapshotDocument::page($plain,$plain['manifest']);
    }

    /** @param array<string,mixed> $manifest
     * @return array<string,mixed> */
    private static function plainManifest(array $manifest): array
    { unset($manifest['ordering_epoch']); $manifest['contract'] = SnapshotDocument::CONTRACT; return $manifest; }

    /** @param array<string,mixed> $manifest
     * @param array<string,mixed> $row */
    private static function context(array $manifest, array $row): void
    {
        if ($row['kind'] !== 'allocation') { return; }
        if ($row['member_faluss_id'] !== $manifest['member_faluss_id']) { throw new ModelViolation('pf_snapshot_context_mismatch'); }
        if ($row['ranking'] !== null && $row['ranking']['ordering_epoch'] !== $manifest['ordering_epoch']) { throw new ModelViolation('pf_snapshot_ranking_epoch'); }
    }
}

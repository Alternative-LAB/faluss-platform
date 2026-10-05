<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Public private-proof DTO, not a ledger or a ranking projection. */
final class SnapshotDocument
{
    public const CONTRACT = 'hub.purchased-pf.snapshot/1.0.0';
    public const PAGE_SIZE = 100;

    /**
     * @param list<array<string,string>> $rows
     * @return array<string,string>
     */
    public static function summary(array $rows): array
    {
        $lots = $allocations = $attributions = [];
        $totals = ['lot_count' => 0, 'allocation_count' => 0, 'original_pf' => 0,
            'cancelled_pf' => 0, 'suspended_pf' => 0, 'net_pf' => 0, 'available_pf' => 0];
        $previous = '';
        foreach ($rows as $row) {
            self::row($row);
            $order = self::order($row);
            if (strcmp($order, $previous) <= 0) {
                throw new ModelViolation('pf_snapshot_duplicate_or_order');
            }
            $previous = $order;
            $lotId = $row['lot_id'];
            if ($row['kind'] === 'lot') {
                $lots[$lotId] = $row;
                $totals['lot_count']++;
                $totals['available_pf'] = self::add($totals['available_pf'], (int) $row['available_pf']);
            } else {
                if (!isset($lots[$lotId]) || $row['source_revision'] !== $lots[$lotId]['source_revision']
                    || ($lots[$lotId]['state'] === 'disputed' ? $row['net_pf'] !== '0' : $row['suspended_pf'] !== '0')
                ) {
                    throw new ModelViolation('pf_snapshot_missing_filiation');
                }
                $allocations[$lotId]['original'] = self::add($allocations[$lotId]['original'] ?? 0, (int) $row['original_pf']);
                $allocations[$lotId]['cancelled'] = self::add($allocations[$lotId]['cancelled'] ?? 0, (int) $row['cancelled_pf']);
                $identity = array_intersect_key($row, array_flip(['attribution_id', 'consumption_id', 'member_faluss_id',
                    'creator_faluss_id', 'client_authority', 'confirmed_at', 'ledger_entry_uuid', 'ledger_fact_sha256', 'attribution_original_pf']));
                $id = $row['attribution_id'];
                $identityHash = hash('sha256', CanonicalJson::encode($identity));
                if (isset($attributions[$id]) && $attributions[$id]['identity_sha256'] !== $identityHash) {
                    throw new ModelViolation('pf_snapshot_missing_filiation');
                }
                $attributions[$id]['identity'] = $identity;
                $attributions[$id]['identity_sha256'] = $identityHash;
                $attributions[$id]['original'] = self::add($attributions[$id]['original'] ?? 0, (int) $row['original_pf']);
                foreach (['original_pf', 'cancelled_pf', 'suspended_pf', 'net_pf'] as $field) {
                    $totals[$field] = self::add($totals[$field], (int) $row[$field]);
                }
                $totals['allocation_count']++;
            }
        }
        foreach ($lots as $lotId => $lot) {
            if ((int) $lot['allocated_original_pf'] !== ($allocations[$lotId]['original'] ?? 0)
                || (int) $lot['allocated_cancelled_pf'] !== ($allocations[$lotId]['cancelled'] ?? 0)
            ) {
                throw new ModelViolation('pf_snapshot_incomplete');
            }
        }
        foreach ($attributions as $attribution) {
            if ($attribution['original'] !== (int) $attribution['identity']['attribution_original_pf']) {
                throw new ModelViolation('pf_snapshot_incomplete');
            }
        }
        $result = array_map(static fn (int $value): string => (string) $value, $totals);
        $result['row_count'] = (string) count($rows);
        $result['page_count'] = (string) max(1, (int) ceil(count($rows) / self::PAGE_SIZE));
        return $result;
    }

    /** @param array<string,string> $row */
    public static function row(array $row): void
    {
        $kind = $row['kind'] ?? '';
        $common = ['kind', 'lot_id', 'source_revision', 'original_pf', 'cancelled_pf'];
        ModelValues::exactKeys($row, array_merge($common, $kind === 'lot'
            ? ['state', 'available_cancelled_pf', 'allocated_original_pf', 'allocated_cancelled_pf', 'available_pf',
                'source_evidence_id', 'source_evidence_sha256']
            : ['attribution_id', 'consumption_id', 'member_faluss_id', 'creator_faluss_id', 'client_authority',
                'attribution_original_pf', 'suspended_pf', 'net_pf', 'confirmed_at', 'ledger_entry_uuid', 'ledger_fact_sha256']));
        ModelValues::uuid($row['lot_id']);
        ModelValues::integer($row['source_revision'], true);
        ModelValues::integer($row['original_pf'], true);
        ModelValues::integer($row['cancelled_pf']);
        if ($kind === 'lot') {
            ModelValues::uuid($row['source_evidence_id']);
            self::digest($row['source_evidence_sha256']);
            foreach (['available_cancelled_pf', 'allocated_original_pf', 'allocated_cancelled_pf', 'available_pf'] as $field) {
                ModelValues::integer($row[$field]);
            }
            if (!in_array($row['state'], ['confirmed', 'disputed', 'partially_cancelled', 'cancelled'], true)
                || (int) $row['cancelled_pf'] !== (int) $row['available_cancelled_pf'] + (int) $row['allocated_cancelled_pf']
                || (int) $row['original_pf'] !== (int) $row['allocated_original_pf'] + (int) $row['available_pf'] + (int) $row['available_cancelled_pf']
                || (int) $row['allocated_cancelled_pf'] > (int) $row['allocated_original_pf']
                || ($row['state'] === 'cancelled' && $row['cancelled_pf'] !== $row['original_pf'])
                || ($row['state'] === 'confirmed' && $row['cancelled_pf'] !== '0')
                || ($row['state'] === 'partially_cancelled' && ((int) $row['cancelled_pf'] === 0 || (int) $row['cancelled_pf'] >= (int) $row['original_pf']))
            ) {
                throw new ModelViolation('pf_snapshot_sum_mismatch');
            }
        } elseif ($kind === 'allocation') {
            foreach (['attribution_id', 'consumption_id', 'member_faluss_id', 'creator_faluss_id', 'ledger_entry_uuid'] as $field) {
                ModelValues::uuid($row[$field]);
            }
            ModelValues::authority($row['client_authority']);
            ModelValues::integer($row['suspended_pf']);
            ModelValues::integer($row['net_pf']);
            ModelValues::integer($row['attribution_original_pf'], true);
            self::digest($row['ledger_fact_sha256']);
            if ($row['client_authority'] !== 'fixture.fans' || $row['member_faluss_id'] === $row['creator_faluss_id']
                || (int) $row['attribution_original_pf'] < (int) $row['original_pf']
                || (int) $row['original_pf'] !== (int) $row['cancelled_pf'] + (int) $row['suspended_pf'] + (int) $row['net_pf']
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $row['confirmed_at']) !== 1
            ) {
                throw new ModelViolation('pf_snapshot_sum_mismatch');
            }
        } else {
            throw new ModelViolation('pf_snapshot_invalid_row');
        }
    }

    /** @param array<string,string> $row */
    public static function order(array $row): string
    {
        return $row['kind'] === 'lot' ? '0.' . $row['lot_id'] : '1.' . $row['attribution_id'] . '.' . $row['lot_id'];
    }

    /** @param array<string,mixed> $manifest */
    public static function manifest(array $manifest, string $member, string $epoch): void
    {
        ModelValues::exactKeys($manifest, ['contract', 'snapshot_id', 'issuer', 'audience', 'member_faluss_id', 'epoch', 'revision',
            'full_sha256', 'created_at', 'lot_count', 'allocation_count', 'row_count', 'page_count',
            'original_pf', 'cancelled_pf', 'suspended_pf', 'net_pf', 'available_pf']);
        if ($manifest['contract'] !== self::CONTRACT || $manifest['issuer'] !== 'fixture.hub' || $manifest['audience'] !== 'fixture.fans'
            || $manifest['member_faluss_id'] !== ModelValues::uuid($member) || $manifest['epoch'] !== ModelValues::uuid($epoch)
        ) {
            throw new ModelViolation('pf_snapshot_context_mismatch');
        }
        ModelValues::uuid($manifest['snapshot_id']);
        ModelValues::utc($manifest['created_at']);
        self::digest($manifest['full_sha256']);
        foreach (['revision', 'lot_count', 'allocation_count', 'row_count', 'page_count', 'original_pf',
            'cancelled_pf', 'suspended_pf', 'net_pf', 'available_pf'] as $field) {
            ModelValues::integer($manifest[$field], in_array($field, ['revision', 'page_count'], true));
        }
        if ((int) $manifest['page_count'] !== max(1, (int) ceil((int) $manifest['row_count'] / self::PAGE_SIZE))
            || (int) $manifest['row_count'] !== (int) $manifest['lot_count'] + (int) $manifest['allocation_count']
            || (int) $manifest['original_pf'] !== (int) $manifest['cancelled_pf'] + (int) $manifest['suspended_pf'] + (int) $manifest['net_pf']
        ) {
            throw new ModelViolation('pf_snapshot_incomplete');
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @param list<array<string,string>> $rows
     */
    public static function complete(array $manifest, array $rows): void
    {
        self::manifest($manifest, $manifest['member_faluss_id'], $manifest['epoch']);
        $summary = self::summary($rows);
        foreach ($summary as $field => $value) {
            if ($manifest[$field] !== $value) {
                throw new ModelViolation('pf_snapshot_incomplete');
            }
        }
        if (!hash_equals($manifest['full_sha256'], hash('sha256', CanonicalJson::encode($rows)))) {
            throw new ModelViolation('pf_snapshot_digest_mismatch');
        }
        foreach ($rows as $row) {
            if ($row['kind'] === 'allocation' && $row['member_faluss_id'] !== $manifest['member_faluss_id']) {
                throw new ModelViolation('pf_snapshot_context_mismatch');
            }
        }
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed> $manifest
     */
    public static function page(array $page, array $manifest): void
    {
        self::manifest($manifest, $manifest['member_faluss_id'], $manifest['epoch']);
        ModelValues::exactKeys($page, ['manifest', 'page_index', 'cursor', 'next_cursor', 'rows']);
        ModelValues::integer($page['page_index']);
        ModelValues::uuid($page['cursor']);
        if ($page['next_cursor'] !== null) {
            ModelValues::uuid($page['next_cursor']);
        }
        $index = (int) $page['page_index'];
        $count = min(self::PAGE_SIZE, (int) $manifest['row_count'] - $index * self::PAGE_SIZE);
        if ($page['manifest'] !== $manifest || $index >= (int) $manifest['page_count']
            || !is_array($page['rows']) || !array_is_list($page['rows']) || count($page['rows']) !== $count
            || (($index === (int) $manifest['page_count'] - 1) !== ($page['next_cursor'] === null))
            || $page['next_cursor'] === $page['cursor']
        ) {
            throw new ModelViolation('pf_snapshot_incomplete');
        }
        $previous = '';
        foreach ($page['rows'] as $row) {
            if (!is_array($row)) {
                throw new ModelViolation('pf_snapshot_invalid_row');
            }
            self::row($row);
            $order = self::order($row);
            if (strcmp($order, $previous) <= 0
                || ($row['kind'] === 'allocation' && $row['member_faluss_id'] !== $manifest['member_faluss_id'])
            ) {
                throw new ModelViolation('pf_snapshot_context_mismatch');
            }
            $previous = $order;
        }
    }

    private static function add(int $left, int $right): int
    {
        if ($left > ModelValues::MAX_INTEGER - $right) {
            throw new ModelViolation('pf_snapshot_sum_mismatch');
        }
        return $left + $right;
    }

    public static function digest(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ModelViolation('pf_snapshot_invalid_digest');
        }
        return $value;
    }
}

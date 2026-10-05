<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Cumulative lot reduction; no money, ledger write or projection policy. */
final class CorrectionPlan
{
    public const FRAGMENT_SIZE = 100;

    /**
     * @param list<array{attribution_id:string,lot_id:string,purchased_pf:string,confirmed_at:string,cancelled_pf:string}> $allocations
     * @return array{available_cancelled_pf:string,allocated_cancelled_pf:string,rows:list<array<string,string>>}
     */
    public static function build(string $original, string $cancelled, bool $disputed, array $allocations): array
    {
        $quantity = (int) ModelValues::integer($original, true);
        $target = (int) ModelValues::integer($cancelled);
        if ($target > $quantity) {
            throw new ModelViolation('invalid_cumulative_cancellation');
        }
        usort($allocations, static fn (array $a, array $b): int => strcmp($b['confirmed_at'], $a['confirmed_at'])
            ?: strcmp($b['attribution_id'], $a['attribution_id']) ?: strcmp($b['lot_id'], $a['lot_id']));
        $used = 0;
        $identifiers = [];
        foreach ($allocations as $allocation) {
            ModelValues::uuid($allocation['attribution_id']);
            ModelValues::uuid($allocation['lot_id']);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $allocation['confirmed_at']) !== 1) {
                throw new ModelViolation('invalid_confirmation_date');
            }
            $id = $allocation['attribution_id'] . '|' . $allocation['lot_id'];
            $amount = (int) ModelValues::integer($allocation['purchased_pf'], true);
            $prior = (int) ModelValues::integer($allocation['cancelled_pf']);
            if (isset($identifiers[$id]) || $used > $quantity - $amount || $prior > $amount) {
                throw new ModelViolation('h4_filiation_failure');
            }
            $identifiers[$id] = true;
            $used += $amount;
        }
        $available = min($target, $quantity - $used);
        $remaining = $target - $available;
        $rows = [];
        foreach ($allocations as $allocation) {
            $amount = (int) $allocation['purchased_pf'];
            $reduction = min($remaining, $amount);
            if ($reduction < (int) $allocation['cancelled_pf']) {
                throw new ModelViolation('h4_cancellation_regression');
            }
            $remaining -= $reduction;
            $rows[] = ['attribution_id' => $allocation['attribution_id'], 'lot_id' => $allocation['lot_id'],
                'original_pf' => $allocation['purchased_pf'], 'cancelled_pf' => (string) $reduction,
                'previous_cancelled_pf' => $allocation['cancelled_pf'],
                'suspended_pf' => $disputed ? (string) ($amount - $reduction) : '0',
                'net_pf' => $disputed ? '0' : (string) ($amount - $reduction)];
        }
        if ($remaining !== 0) {
            throw new ModelViolation('h4_filiation_failure');
        }
        return ['available_cancelled_pf' => (string) $available,
            'allocated_cancelled_pf' => (string) ($target - $available), 'rows' => $rows];
    }
}

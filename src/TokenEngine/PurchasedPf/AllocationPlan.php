<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

/** Pure FIFO model: planned units are neither reserved nor consumed. */
final class AllocationPlan
{
    /**
     * @param list<array{lot_id:string,member_faluss_id:string,purchased_pf:string,evidence_id:string,source_revision:string,accepted_at:string}> $lots
     * @return list<array{lot_id:string,evidence_id:string,source_revision:string,purchased_pf:string}>
     */
    public static function forIntent(AttributionIntent $intent, array $lots): array
    {
        if (PHP_INT_SIZE < 8) {
            throw new ModelViolation('model_requires_64_bit');
        }
        $seen = [];
        foreach ($lots as $lot) {
            ModelValues::exactKeys($lot, ['lot_id', 'member_faluss_id', 'purchased_pf', 'evidence_id', 'source_revision', 'accepted_at']);
            $lotId = ModelValues::uuid($lot['lot_id']);
            ModelValues::uuid($lot['evidence_id']);
            ModelValues::integer($lot['source_revision'], true);
            ModelValues::integer($lot['purchased_pf'], true);
            if (isset($seen[$lotId]) || $lot['member_faluss_id'] !== $intent->values['member_faluss_id']
                || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/D', $lot['accepted_at']) !== 1
            ) {
                throw new ModelViolation('invalid_lot_filiation');
            }
            ModelValues::utc(str_replace(' ', 'T', substr($lot['accepted_at'], 0, 19)) . 'Z');
            $seen[$lotId] = true;
        }
        usort($lots, static fn (array $a, array $b): int => strcmp($a['accepted_at'], $b['accepted_at']) ?: strcmp($a['lot_id'], $b['lot_id']));
        $remaining = (int) $intent->values['purchased_pf'];
        $plan = [];
        foreach ($lots as $lot) {
            if ($remaining === 0) {
                break;
            }
            if (count($plan) === 32) {
                throw new ModelViolation('model_allocation_limit');
            }
            $quantity = min($remaining, (int) $lot['purchased_pf']);
            $plan[] = ['lot_id' => $lot['lot_id'], 'evidence_id' => $lot['evidence_id'],
                'source_revision' => $lot['source_revision'], 'purchased_pf' => (string) $quantity];
            $remaining -= $quantity;
        }
        if ($remaining !== 0) {
            throw new ModelViolation('model_quantity_unavailable');
        }

        return $plan;
    }
}

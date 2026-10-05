<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf\Protocol;

use Faluss\Platform\TokenEngine\PurchasedPf\AttributionIntent;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;

/** Public wire validation of a historical private consumption, not its current H4 state or a score. */
final class PrivateReceipt
{
    /** @param array<string,mixed> $payload */
    public static function validate(array $payload, string $issuer, string $audience, AttributionIntent $intent): void
    {
        ModelValues::exactKeys($payload, ['contract', 'kind', 'issuer', 'audience', 'receipt_id', 'revision',
            'attribution_id', 'reservation_id', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf',
            'policy_version', 'ledger_entry_uuid', 'confirmed_at', 'allocations']);
        if ($payload['contract'] !== DelegatedContext::CONTRACT || $payload['kind'] !== SignedEnvelope::RECEIPT
            || $payload['issuer'] !== $issuer || $payload['audience'] !== $audience || $payload['revision'] !== '1'
        ) {
            throw new ModelViolation('pf_receipt_mismatch');
        }
        foreach (['attribution_id', 'member_faluss_id', 'creator_faluss_id', 'purchased_pf', 'policy_version'] as $field) {
            if ($payload[$field] !== $intent->values[$field]) {
                throw new ModelViolation('pf_receipt_mismatch');
            }
        }
        foreach (['receipt_id', 'reservation_id', 'ledger_entry_uuid'] as $field) {
            ModelValues::uuid($payload[$field]);
        }
        ModelValues::utc($payload['confirmed_at']);
        $allocations = $payload['allocations'];
        if (!is_array($allocations) || !array_is_list($allocations) || count($allocations) < 1 || count($allocations) > 32) {
            throw new ModelViolation('pf_receipt_mismatch');
        }
        $total = 0;
        $previous = '';
        $seen = [];
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) {
                throw new ModelViolation('pf_receipt_mismatch');
            }
            ModelValues::exactKeys($allocation, ['allocation_id', 'lot_id', 'evidence_id', 'source_revision',
                'purchase_authority', 'purchase_reference', 'purchased_pf']);
            $lot = ModelValues::uuid($allocation['lot_id']);
            if (!is_string($allocation['allocation_id']) || strcmp($allocation['allocation_id'], $previous) <= 0 || isset($seen[$lot])
                || $allocation['allocation_id'] !== hash('sha256', $intent->values['attribution_id'] . '|' . $lot)
            ) {
                throw new ModelViolation('pf_receipt_mismatch');
            }
            $previous = $allocation['allocation_id'];
            $seen[$lot] = true;
            ModelValues::uuid($allocation['evidence_id']);
            ModelValues::integer($allocation['source_revision'], true);
            ModelValues::authority($allocation['purchase_authority']);
            ModelValues::reference($allocation['purchase_reference']);
            $quantity = (int) ModelValues::integer($allocation['purchased_pf'], true);
            if ($total > (int) $intent->values['purchased_pf'] - $quantity) {
                throw new ModelViolation('pf_receipt_mismatch');
            }
            $total += $quantity;
        }
        if ((string) $total !== $intent->values['purchased_pf']) {
            throw new ModelViolation('pf_receipt_mismatch');
        }
    }
}
